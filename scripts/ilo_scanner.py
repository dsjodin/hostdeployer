#!/usr/bin/env python3

import json
import sys
import os
import ipaddress
import threading
import time
import subprocess
import logging
from concurrent.futures import ThreadPoolExecutor
import requests
from requests.packages.urllib3.exceptions import InsecureRequestWarning

requests.packages.urllib3.disable_warnings(InsecureRequestWarning)

# Set up logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("/srv/autodeploy/logs/ilo_scanner.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger("ilo_scanner")

def format_mac(mac):
    """Format MAC address consistently"""
    # Remove any separator and convert to lowercase
    mac = mac.lower().replace(':', '').replace('-', '')
    # Format with colons
    return ':'.join(mac[i:i+2] for i in range(0, len(mac), 2))

def load_global_config():
    """Load the global configuration file"""
    try:
        with open("/srv/autodeploy/config/global_config.json", "r") as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Failed to load global config: {e}")
        sys.exit(1)

def load_credentials():
    """Load secure credentials from credentials.json"""
    try:
        with open("/srv/autodeploy/config/credentials.json", "r") as f:
            credentials = json.load(f)
            return credentials.get("ilo", {})
    except Exception as e:
        logger.error(f"Failed to load credentials: {e}")
        return {}

def get_ilo_credentials(mac_address=None):
    """Get iLO credentials, with host-specific credentials if available"""
    try:
        with open("/srv/autodeploy/config/credentials.json", "r") as f:
            credentials = json.load(f)
            
            # Get global iLO credentials
            ilo_creds = credentials.get("ilo", {})
            username = ilo_creds.get("admin_user")
            password = ilo_creds.get("admin_password")
            
            # Check for host-specific credentials if MAC is provided
            if mac_address and "hosts" in ilo_creds:
                mac_formatted = format_mac(mac_address)
                if mac_formatted in ilo_creds["hosts"]:
                    host_creds = ilo_creds["hosts"][mac_formatted]
                    # Override with host-specific values if available
                    if "username" in host_creds:
                        username = host_creds["username"]
                    if "password" in host_creds:
                        password = host_creds["password"]
                    logger.info(f"Using host-specific iLO credentials for {mac_address}")
            
            return username, password
    except Exception as e:
        logger.error(f"Failed to load credentials: {e}")
        return None, None

def load_hosts_config():
    """Load the hosts configuration file"""
    config = load_global_config()
    hosts_path = config["paths"]["hosts_config"]
    
    try:
        if os.path.exists(hosts_path):
            with open(hosts_path, "r") as f:
                return json.load(f)
        else:
            return {"hosts": []}
    except Exception as e:
        logger.error(f"Failed to load hosts config: {e}")
        return {"hosts": []}

def save_hosts_config(hosts_config):
    """Save the hosts configuration file"""
    config = load_global_config()
    hosts_path = config["paths"]["hosts_config"]
    
    try:
        # Create directory if it doesn't exist
        os.makedirs(os.path.dirname(hosts_path), exist_ok=True)
        
        with open(hosts_path, "w") as f:
            json.dump(hosts_config, f, indent=2)
        logger.info(f"Hosts configuration saved to {hosts_path}")
    except Exception as e:
        logger.error(f"Failed to save hosts config: {e}")

def check_host_reachable(ip):
    """Check if a host is reachable via ping"""
    try:
        result = subprocess.run(
            ["ping", "-c", "1", "-W", "1", ip],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
        return result.returncode == 0
    except:
        return False

def test_ilo_auth(ip, username, password):
    """Test iLO authentication without using the redfish library"""
    try:
        # Try a direct REST call to test credentials
        url = f"https://{ip}/redfish/v1/Systems/1"
        response = requests.get(
            url, 
            auth=(username, password),
            verify=False,
            timeout=5
        )
        
        if response.status_code == 200:
            logger.info(f"Successfully authenticated to iLO at {ip}")
            return True
        else:
            logger.warning(f"Authentication failed for iLO at {ip}: Status code {response.status_code}")
            logger.debug(f"Response: {response.text}")
            return False
    except Exception as e:
        logger.warning(f"Error testing iLO authentication at {ip}: {e}")
        return False

def get_mac_addresses_from_response(response_text):
    """Extract MAC addresses from a JSON response string"""
    mac_addresses = []
    try:
        data = json.loads(response_text)
        
        # Look for common MAC address fields in the response
        if "MacAddress" in data:
            mac_addresses.append(data["MacAddress"])
        elif "MACAddress" in data:
            mac_addresses.append(data["MACAddress"])
        
        # Look in network adapters if present
        if "NetworkAdapters" in data and isinstance(data["NetworkAdapters"], list):
            for adapter in data["NetworkAdapters"]:
                if "MacAddress" in adapter:
                    mac_addresses.append(adapter["MacAddress"])
        
        # Look in EthernetInterfaces if present
        if "EthernetInterfaces" in data and isinstance(data["EthernetInterfaces"], list):
            for interface in data["EthernetInterfaces"]:
                if "MACAddress" in interface:
                    mac_addresses.append(interface["MACAddress"])
    except:
        pass
    
    return mac_addresses

def detect_secure_boot_status(ip, username, password):
    """Enhanced secure boot detection with multiple methods"""
    secure_boot_status = "Unknown"
    
    # Method 1: Try Bios Attributes
    try:
        bios_url = f"https://{ip}/redfish/v1/Systems/1/Bios"
        bios_response = requests.get(
            bios_url,
            auth=(username, password),
            verify=False,
            timeout=5
        )
        
        if bios_response.status_code == 200:
            bios_data = bios_response.json()
            
            # Log the entire BIOS data for debugging
            logger.info(f"BIOS data for {ip}: {json.dumps(bios_data)}")
            
            # Look for different possible attribute names
            attributes = bios_data.get("Attributes", {})
            
            # Common attribute names for secure boot
            secure_boot_keys = ["SecureBoot", "SecureBootEnable", "SecureBootStatus", 
                               "Secure Boot", "SecureBootOption", "HPE_SecureBoot"]
            
            # Check for any matching keys
            for key in secure_boot_keys:
                if key in attributes:
                    value = attributes[key]
                    logger.info(f"Found secure boot attribute '{key}' with value '{value}' for {ip}")
                    
                    # Convert various status formats to standard format
                    if isinstance(value, bool):
                        secure_boot_status = "Enabled" if value else "Disabled"
                    elif isinstance(value, str):
                        if value.lower() in ["enabled", "enable", "on", "true", "yes", "1"]:
                            secure_boot_status = "Enabled"
                        elif value.lower() in ["disabled", "disable", "off", "false", "no", "0"]:
                            secure_boot_status = "Disabled"
                        else:
                            # Keep actual value if we can't normalize it
                            secure_boot_status = value
                    
                    # Break after finding first valid attribute
                    if secure_boot_status != "Unknown":
                        break
            
            # Scan all attributes for anything that might be secure boot related
            if secure_boot_status == "Unknown":
                logger.info(f"Scanning all BIOS attributes for secure boot related settings for {ip}")
                for key, value in attributes.items():
                    if "secure" in key.lower() and "boot" in key.lower():
                        logger.info(f"Found potential secure boot attribute '{key}' with value '{value}' for {ip}")
    except Exception as e:
        logger.warning(f"Error checking BIOS for secure boot status at {ip}: {e}")
    
    # Method 2: Try SecureBoot resource if available
    if secure_boot_status == "Unknown":
        try:
            secure_boot_url = f"https://{ip}/redfish/v1/Systems/1/SecureBoot"
            secure_boot_response = requests.get(
                secure_boot_url,
                auth=(username, password),
                verify=False,
                timeout=5
            )
            
            if secure_boot_response.status_code == 200:
                secure_boot_data = secure_boot_response.json()
                logger.info(f"SecureBoot resource data for {ip}: {json.dumps(secure_boot_data)}")
                
                # Look for the SecureBootEnable property
                if "SecureBootEnable" in secure_boot_data:
                    secure_boot_status = "Enabled" if secure_boot_data["SecureBootEnable"] else "Disabled"
                    logger.info(f"Found SecureBootEnable={secure_boot_data['SecureBootEnable']} for {ip}")
                # Look for SecureBootCurrentBoot property
                elif "SecureBootCurrentBoot" in secure_boot_data:
                    if secure_boot_data["SecureBootCurrentBoot"] == "Enabled":
                        secure_boot_status = "Enabled"
                    else:
                        secure_boot_status = "Disabled"
                    logger.info(f"Found SecureBootCurrentBoot={secure_boot_data['SecureBootCurrentBoot']} for {ip}")
        except Exception as e:
            logger.warning(f"Error checking dedicated SecureBoot resource at {ip}: {e}")
    
    # Method 3: Try Boot resource if available
    if secure_boot_status == "Unknown":
        try:
            boot_url = f"https://{ip}/redfish/v1/Systems/1/Boot"
            boot_response = requests.get(
                boot_url,
                auth=(username, password),
                verify=False,
                timeout=5
            )
            
            if boot_response.status_code == 200:
                boot_data = boot_response.json()
                logger.info(f"Boot resource data for {ip}: {json.dumps(boot_data)}")
                
                # Check for secure boot settings in Boot resource
                if "SecureBoot" in boot_data:
                    secure_boot_status = "Enabled" if boot_data["SecureBoot"] else "Disabled"
                    logger.info(f"Found SecureBoot={boot_data['SecureBoot']} in Boot resource for {ip}")
        except Exception as e:
            logger.warning(f"Error checking Boot resource at {ip}: {e}")
    
    # Method 4: Try BIOS registry if available
    if secure_boot_status == "Unknown":
        try:
            registry_url = f"https://{ip}/redfish/v1/Registries"
            registry_response = requests.get(
                registry_url,
                auth=(username, password),
                verify=False,
                timeout=5
            )
            
            if registry_response.status_code == 200:
                registry_data = registry_response.json()
                logger.info(f"Found registry resource at {ip}")
                
                # This is a more complex search that would require additional parsing
                # Just log that we found it for potential future enhancements
        except Exception as e:
            logger.warning(f"Error checking Registry resource at {ip}: {e}")
    
    logger.info(f"Final secure boot status for {ip}: {secure_boot_status}")
    return secure_boot_status

def scan_ilo_with_requests(ip, username, password):
    """Scan a single iLO using direct HTTP requests instead of relying on redfish library"""
    try:
        # Base URL for Redfish API
        base_url = f"https://{ip}/redfish/v1"
        
        # Get system information
        system_url = f"{base_url}/Systems/1"
        system_response = requests.get(
            system_url,
            auth=(username, password),
            verify=False,
            timeout=10
        )
        
        if system_response.status_code != 200:
            logger.error(f"Failed to get system information from {ip}: Status {system_response.status_code}")
            return None
        
        system_data = system_response.json()
        
        # Try to get network information
        mac_addresses = []
        
        # Try NetworkInterfaces endpoint
        try:
            network_url = f"{base_url}/Systems/1/NetworkInterfaces"
            network_response = requests.get(
                network_url,
                auth=(username, password),
                verify=False,
                timeout=5
            )
            
            if network_response.status_code == 200:
                network_data = network_response.json()
                
                # Get each network interface
                if "Members" in network_data and isinstance(network_data["Members"], list):
                    for member in network_data["Members"]:
                        if "@odata.id" in member:
                            interface_url = f"https://{ip}{member['@odata.id']}"
                            interface_response = requests.get(
                                interface_url,
                                auth=(username, password),
                                verify=False,
                                timeout=5
                            )
                            
                            if interface_response.status_code == 200:
                                # Extract MAC addresses
                                macs = get_mac_addresses_from_response(interface_response.text)
                                mac_addresses.extend(macs)
        except Exception as e:
            logger.warning(f"Error getting network interfaces for {ip}: {e}")
        
        # Try EthernetInterfaces endpoint if no MACs found yet
        if not mac_addresses:
            try:
                eth_url = f"{base_url}/Systems/1/EthernetInterfaces"
                eth_response = requests.get(
                    eth_url,
                    auth=(username, password),
                    verify=False,
                    timeout=5
                )
                
                if eth_response.status_code == 200:
                    eth_data = eth_response.json()
                    
                    # Get each ethernet interface
                    if "Members" in eth_data and isinstance(eth_data["Members"], list):
                        for member in eth_data["Members"]:
                            if "@odata.id" in member:
                                interface_url = f"https://{ip}{member['@odata.id']}"
                                interface_response = requests.get(
                                    interface_url,
                                    auth=(username, password),
                                    verify=False,
                                    timeout=5
                                )
                                
                                if interface_response.status_code == 200:
                                    interface_data = interface_response.json()
                                    if "MACAddress" in interface_data:
                                        mac_addresses.append(interface_data["MACAddress"])
            except Exception as e:
                logger.warning(f"Error getting ethernet interfaces for {ip}: {e}")
        
        # Use enhanced secure boot detection
        secure_boot_status = detect_secure_boot_status(ip, username, password)
        
        # Create result with collected information
        result = {
            "ilo_ip": ip,
            "serial_number": system_data.get("SerialNumber", "Unknown"),
            "mac_address": mac_addresses[0] if mac_addresses else "Unknown",
            "additional_macs": mac_addresses[1:] if len(mac_addresses) > 1 else [],
            "model": system_data.get("Model", "Unknown"),
            "manufacturer": system_data.get("Manufacturer", "Unknown"),
            "bios_version": system_data.get("BiosVersion", "Unknown"),
            "secure_boot_status": secure_boot_status,
            "hostname": "",
            "fqdn": "",
            "management_ip": "",
            "management_netmask": "",
            "management_gateway": "",
            "vlan_id": 0,
            "datastore": {
                "name": "datastore1",
                "drives": []
            },
            "vlans": {
                "management": 0,
                "vmotion": 0,
                "storage": 0
            },
            "deployment_status": "pending",
            "deployment_time": None
        }
        
        logger.info(f"Successfully scanned iLO at {ip}, found {result['model']} with S/N: {result['serial_number']}")
        return result
        
    except Exception as e:
        logger.error(f"Error scanning iLO at {ip} with direct requests: {e}")
        return None

def scan_ilo(ip, username, password):
    """Scan a single iLO IP and retrieve system information"""
    logger.info(f"Checking iLO at {ip}")
    
    if not check_host_reachable(ip):
        logger.debug(f"Host {ip} not reachable, skipping")
        return None
    
    # First test if we can authenticate
    if not test_ilo_auth(ip, username, password):
        logger.error(f"Authentication failed for iLO at {ip}, skipping")
        return None
    
    # Use direct HTTP requests instead of relying on redfish library
    return scan_ilo_with_requests(ip, username, password)

def scan_ip_range(start_ip, end_ip, username, password, max_threads=5):
    """Scan a range of IP addresses for iLO systems"""
    start = ipaddress.IPv4Address(start_ip)
    end = ipaddress.IPv4Address(end_ip)
    
    ip_list = [str(ipaddress.IPv4Address(ip)) for ip in range(int(start), int(end) + 1)]
    logger.info(f"Starting scan of {len(ip_list)} IP addresses from {start_ip} to {end_ip}")
    
    # Debug: Print credentials being used
    logger.info(f"Using default iLO credentials - Username: {username}, Password: {'*' * len(password) if password else 'None'}")
    
    results = []
    with ThreadPoolExecutor(max_workers=max_threads) as executor:
        futures = {executor.submit(scan_ilo, ip, username, password): ip for ip in ip_list}
        
        for future in futures:
            result = future.result()
            if result:
                # For successful results, check if we need to use host-specific credentials
                # and re-scan if needed
                mac = result.get("mac_address")
                if mac and mac != "Unknown":
                    host_username, host_password = get_ilo_credentials(mac)
                    if (host_username and host_username != username) or (host_password and host_password != password):
                        # We have different host-specific credentials, re-scan with those
                        logger.info(f"Re-scanning {result['ilo_ip']} with host-specific credentials")
                        new_result = scan_ilo(result['ilo_ip'], host_username, host_password)
                        if new_result:
                            results.append(new_result)
                            continue
                
                # Add the original result if no re-scan was done or if re-scan failed
                results.append(result)
    
    logger.info(f"Scan complete. Found {len(results)} iLO systems")
    return results

def update_hosts_config(scan_results):
    """Update hosts.json with scan results"""
    hosts_config = load_hosts_config()
    hosts_list = hosts_config["hosts"]
    updated = 0
    added = 0
    
    for result in scan_results:
        # Check if host already exists by serial number
        existing_host = next((host for host in hosts_list if host.get("serial_number") == result["serial_number"] and result["serial_number"] != "Unknown"), None)
        
        if existing_host:
            # Update existing entry
            existing_host["ilo_ip"] = result["ilo_ip"]
            existing_host["mac_address"] = result["mac_address"]
            existing_host["secure_boot_status"] = result["secure_boot_status"]
            updated += 1
        else:
            # Add new entry
            hosts_list.append(result)
            added += 1
    
    # Save updated config
    hosts_config["hosts"] = hosts_list
    save_hosts_config(hosts_config)
    
    logger.info(f"Updated {updated} existing hosts and added {added} new hosts")
    return updated, added

def main():
    """Main entry point"""
    config = load_global_config()
    ilo_config = config["ilo"]
    
    # Load default credentials using the new function
    username, password = get_ilo_credentials()
    
    # Fall back to global config if not found in credentials.json
    if not username:
        username = ilo_config.get("admin_user")
        logger.info("Using admin_user from global config")
    
    if not password:
        password = ilo_config.get("admin_password")
        logger.info("Using admin_password from global config")
    
    if not username or not password:
        logger.error("iLO credentials not found in configuration")
        sys.exit(1)
        
    # Print the loaded configuration
    logger.info(f"Loaded iLO scan configuration - Range: {ilo_config['scan_range_start']} to {ilo_config['scan_range_end']}")
    logger.info(f"Using username: {username}")
    
    # Scan the configured IP range
    scan_results = scan_ip_range(
        ilo_config["scan_range_start"],
        ilo_config["scan_range_end"],
        username,
        password
    )
    
    # Update the hosts configuration
    updated, added = update_hosts_config(scan_results)
    
    print(f"Scan complete. Found {len(scan_results)} iLO systems.")
    print(f"Updated {updated} existing entries and added {added} new entries.")

if __name__ == "__main__":
    main()