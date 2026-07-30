#!/usr/bin/env python3

import argparse
import ipaddress
import json
import logging
import os
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests
import urllib3

from autodeploy_api import ApiError, AutodeployApi

# iLO ships a self-signed certificate by default, so verification is off and
# the resulting warning would otherwise be printed once per request.
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# All paths derive from AUTODEPLOY_ROOT so the tree can be relocated without
# editing the scripts.
AUTODEPLOY_ROOT = os.environ.get("AUTODEPLOY_ROOT", "/srv/autodeploy")
CONFIG_DIR = os.path.join(AUTODEPLOY_ROOT, "config")
LOG_DIR = os.path.join(AUTODEPLOY_ROOT, "logs")
GLOBAL_CONFIG = os.path.join(CONFIG_DIR, "global_config.json")

os.makedirs(LOG_DIR, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, "ilo_scanner.log")),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger("ilo_scanner")

# Cached so a 254-address scan does not re-read and re-parse the config once
# per host.
_global_config = None
_api = None

def format_mac(mac):
    """Format MAC address consistently"""
    # Remove any separator and convert to lowercase
    mac = mac.lower().replace(':', '').replace('-', '')
    # Format with colons
    return ':'.join(mac[i:i+2] for i in range(0, len(mac), 2))

def load_global_config():
    """Load (and cache) the global configuration file."""
    global _global_config

    if _global_config is None:
        try:
            with open(GLOBAL_CONFIG, "r") as f:
                _global_config = json.load(f)
        except Exception as e:
            logger.error(f"Failed to load global config from {GLOBAL_CONFIG}: {e}")
            sys.exit(1)

    return _global_config

def api():
    """The shared API client, created on first use."""
    global _api

    if _api is None:
        _api = AutodeployApi()

    return _api


def get_ilo_credentials(mac_address=None):
    """Return (username, password), preferring host-specific overrides.

    The API applies the override, so this no longer has to know that the file
    stores them under an ilo.hosts.<mac> key.
    """
    creds = api().get_credentials("ilo", mac_address)

    if mac_address and creds:
        logger.debug(f"Using iLO credentials resolved for {mac_address}")

    return creds.get("admin_user") or creds.get("username"), \
        creds.get("admin_password") or creds.get("password")


def check_host_reachable(ip):
    """Check if a host is reachable via ping"""
    try:
        result = subprocess.run(
            ["ping", "-c", "1", "-W", "1", ip],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            # A host that does not answer is the expected case, not an error.
            check=False,
        )
        return result.returncode == 0
    except OSError:
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
    except (ValueError, TypeError) as e:
        logger.debug(f"Could not parse MAC addresses from response: {e}")

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
            
            # The full attribute set is large and contains site-specific BIOS
            # settings; log it only at DEBUG.
            logger.debug(f"BIOS data for {ip}: {json.dumps(bios_data)}")
            
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
                logger.debug(f"SecureBoot resource data for {ip}: {json.dumps(secure_boot_data)}")
                
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
                logger.debug(f"Boot resource data for {ip}: {json.dumps(boot_data)}")
                
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
                # Only noted, not parsed: the registry would have to be walked
                # to resolve attribute names, and every iLO seen so far reports
                # secure boot through the paths tried above.
                logger.debug(f"Registry resource present at {ip}, not parsed")
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

def scan_ip_range(start_ip, end_ip, username, password, max_threads=16):
    """Scan a range of IP addresses for iLO systems."""
    try:
        start = int(ipaddress.IPv4Address(start_ip))
        end = int(ipaddress.IPv4Address(end_ip))
    except ipaddress.AddressValueError as e:
        logger.error(f"Invalid scan range {start_ip}-{end_ip}: {e}")
        return []

    if start > end:
        logger.error(f"Scan range start {start_ip} is higher than the end {end_ip}")
        return []

    if end - start > 4096:
        logger.error(f"Refusing to scan {end - start + 1} addresses; narrow the range")
        return []

    ip_list = [str(ipaddress.IPv4Address(ip)) for ip in range(start, end + 1)]
    logger.info(f"Starting scan of {len(ip_list)} IP addresses from {start_ip} to {end_ip}")
    logger.info(f"Using iLO username: {username}")

    results = []
    with ThreadPoolExecutor(max_workers=max_threads) as executor:
        futures = {executor.submit(scan_ilo, ip, username, password): ip for ip in ip_list}

        # as_completed() so a slow host does not stall the rest, and each
        # result is unwrapped in its own try: one raising future used to abort
        # the entire scan and discard every host found so far.
        for future in as_completed(futures):
            ip = futures[future]
            try:
                result = future.result()
            except Exception as e:
                logger.warning(f"Scan of {ip} raised an error: {e}")
                continue

            if not result:
                continue

            # Re-scan with host-specific credentials when they differ.
            mac = result.get("mac_address")
            if mac and mac != "Unknown":
                host_username, host_password = get_ilo_credentials(mac)
                if (host_username, host_password) != (username, password):
                    logger.info(f"Re-scanning {result['ilo_ip']} with host-specific credentials")
                    try:
                        new_result = scan_ilo(result["ilo_ip"], host_username, host_password)
                    except Exception as e:
                        logger.warning(f"Re-scan of {result['ilo_ip']} failed: {e}")
                        new_result = None

                    if new_result:
                        results.append(new_result)
                        continue

            results.append(result)

    logger.info(f"Scan complete. Found {len(results)} iLO systems")
    return results

def update_hosts_config(scan_results):
    """Send scan results to the API, which merges them into the inventory.

    The matching rules -- serial first, then any known MAC, and never
    overwrite an existing mac_address -- now live in lib/store.php next to the
    storage and inside its lock.
    """
    return api().merge_discovered(scan_results)


def main():
    """Main entry point"""
    parser = argparse.ArgumentParser(description="Scan a range of iLO interfaces for HPE servers")
    parser.add_argument("--start", help="First iLO IP to scan (defaults to global_config.json)")
    parser.add_argument("--end", help="Last iLO IP to scan (defaults to global_config.json)")
    parser.add_argument("--threads", type=int, default=16, help="Concurrent scans (default: 16)")
    parser.add_argument("--dry-run", action="store_true", help="Scan but do not update the inventory")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")
    args = parser.parse_args()

    if args.verbose:
        logger.setLevel(logging.DEBUG)

    config = load_global_config()
    ilo_config = config.get("ilo", {})

    username, password = get_ilo_credentials()

    # Fall back to global config (deprecated: credentials belong in
    # credentials.json, which is not world readable).
    username = username or ilo_config.get("admin_user")
    password = password or ilo_config.get("admin_password")

    if not username or not password:
        logger.error("iLO credentials not found; set them in config/credentials.json under 'ilo'")
        return 1

    start_ip = args.start or ilo_config.get("scan_range_start")
    end_ip = args.end or ilo_config.get("scan_range_end")

    if not start_ip or not end_ip:
        logger.error("No iLO scan range configured; set ilo.scan_range_start and ilo.scan_range_end")
        return 1

    logger.info(f"iLO scan range: {start_ip} to {end_ip}")

    scan_results = scan_ip_range(start_ip, end_ip, username, password, max_threads=args.threads)

    if args.dry_run:
        print(f"Scan complete (dry run). Found {len(scan_results)} iLO systems; the inventory was not modified.")
        return 0

    updated, added = update_hosts_config(scan_results)

    print(f"Scan complete. Found {len(scan_results)} iLO systems.")
    print(f"Updated {updated} existing entries and added {added} new entries.")
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except ApiError as e:
        logger.error(str(e))
        sys.exit(1)