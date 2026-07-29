#!/usr/bin/env python3

import json
import sys
import argparse
import logging
import redfish
import time
import os
from requests.packages.urllib3.exceptions import InsecureRequestWarning
import requests

requests.packages.urllib3.disable_warnings(InsecureRequestWarning)

# Set up logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("/srv/autodeploy/logs/secure_boot_manager.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger("secure_boot_manager")

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

def load_hosts_config():
    """Load the hosts configuration file"""
    config = load_global_config()
    hosts_path = config["paths"]["hosts_config"]
    
    try:
        if os.path.exists(hosts_path):
            with open(hosts_path, "r") as f:
                return json.load(f)
        else:
            logger.error(f"Hosts configuration file not found: {hosts_path}")
            sys.exit(1)
    except Exception as e:
        logger.error(f"Failed to load hosts config: {e}")
        sys.exit(1)

def save_hosts_config(hosts_config):
    """Save the hosts configuration file"""
    config = load_global_config()
    hosts_path = config["paths"]["hosts_config"]
    
    try:
        with open(hosts_path, "w") as f:
            json.dump(hosts_config, f, indent=2)
        logger.info(f"Hosts configuration saved to {hosts_path}")
    except Exception as e:
        logger.error(f"Failed to save hosts config: {e}")
        sys.exit(1)

def format_mac(mac):
    """Format MAC address consistently"""
    mac = mac.lower().replace(':', '').replace('-', '')
    return ':'.join(mac[i:i+2] for i in range(0, len(mac), 2))

def update_secure_boot(ilo_ip, username, password, enable=True):
    """Enable or disable secure boot on an iLO system"""
    try:
        logger.info(f"{'Enabling' if enable else 'Disabling'} secure boot on {ilo_ip}")
        
        # Connect to the iLO Redfish API
        redfish_obj = redfish.RedfishClient(
            base_url=f"https://{ilo_ip}",
            username=username,
            password=password,
            default_prefix='/redfish/v1'
        )
        redfish_obj.login(auth="session")
        
        # Get current BIOS settings
        bios_response = redfish_obj.get("/Systems/1/Bios")
        bios_data = bios_response.dict
        
        # Check if secure boot setting exists
        if "Attributes" not in bios_data or "SecureBoot" not in bios_data["Attributes"]:
            logger.error(f"Secure Boot settings not found for {ilo_ip}")
            redfish_obj.logout()
            return False
        
        current_status = bios_data["Attributes"]["SecureBoot"]
        logger.info(f"Current secure boot status for {ilo_ip}: {current_status}")
        
        # Only update if status needs to change
        if (enable and current_status == "Enabled") or (not enable and current_status == "Disabled"):
            logger.info(f"Secure boot already {'enabled' if enable else 'disabled'} on {ilo_ip}, no change needed")
            redfish_obj.logout()
            return True
        
        # Update BIOS settings
        body = {
            "Attributes": {
                "SecureBoot": "Enabled" if enable else "Disabled"
            }
        }
        
        # Send update request
        update_response = redfish_obj.patch("/Systems/1/Bios/Settings", body=body)
        if update_response.status != 200:
            logger.error(f"Failed to update secure boot settings for {ilo_ip}: {update_response.text}")
            redfish_obj.logout()
            return False
        
        # Reset the system to apply changes
        reset_body = {"ResetType": "ForceRestart"}
        reset_response = redfish_obj.post("/Systems/1/Actions/ComputerSystem.Reset", body=reset_body)
        
        if reset_response.status != 200:
            logger.error(f"Failed to reset system {ilo_ip}: {reset_response.text}")
            redfish_obj.logout()
            return False
        
        logger.info(f"Successfully {'enabled' if enable else 'disabled'} secure boot on {ilo_ip} and restarted the system")
        
        # Logout from iLO
        redfish_obj.logout()
        return True
    
    except Exception as e:
        logger.error(f"Error updating secure boot for {ilo_ip}: {e}")
        return False

def find_host_by_mac(mac):
    """Find a host in the configuration by MAC address"""
    hosts_config = load_hosts_config()
    formatted_mac = format_mac(mac)
    
    for host in hosts_config.get("hosts", []):
        if format_mac(host.get("mac_address", "")) == formatted_mac:
            return host
    
    logger.error(f"Host with MAC {mac} not found in configuration")
    return None

def toggle_secure_boot(mac, enable=True):
    """Toggle secure boot for a host with the given MAC address"""
    global_config = load_global_config()
    host = find_host_by_mac(mac)
    
    if not host:
        logger.error(f"Host with MAC {mac} not found")
        return False
    
    if not host.get("ilo_ip"):
        logger.error(f"Host with MAC {mac} does not have an iLO IP configured")
        return False
    
    # Load credentials from credentials file
    credentials = load_credentials()
    username = credentials.get("admin_user") or global_config["ilo"].get("admin_user")
    password = credentials.get("admin_password") or global_config["ilo"].get("admin_password")
    
    if not username or not password:
        logger.error("iLO credentials not found in configuration")
        return False
    
    result = update_secure_boot(
        host["ilo_ip"],
        username,
        password,
        enable
    )
    
    if result:
        # Update host status in configuration
        hosts_config = load_hosts_config()
        for h in hosts_config.get("hosts", []):
            if format_mac(h.get("mac_address", "")) == format_mac(mac):
                h["secure_boot_status"] = "enabled" if enable else "disabled"
                break
        
        save_hosts_config(hosts_config)
        logger.info(f"Updated secure boot status for host with MAC {mac} to {'enabled' if enable else 'disabled'}")
    
    return result

def main():
    """Main entry point"""
    parser = argparse.ArgumentParser(description="Manage secure boot settings for HPE servers")
    parser.add_argument("--mac", required=True, help="MAC address of the target server")
    parser.add_argument("--action", required=True, choices=["enable", "disable"], help="Action to perform")
    
    args = parser.parse_args()
    
    result = toggle_secure_boot(args.mac, args.action == "enable")
    
    if result:
        print(f"Successfully {args.action}d secure boot for host with MAC {args.mac}")
        return 0
    else:
        print(f"Failed to {args.action} secure boot for host with MAC {args.mac}")
        return 1

if __name__ == "__main__":
    sys.exit(main())