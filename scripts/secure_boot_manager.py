#!/usr/bin/env python3

import argparse
import json
import logging
import os
import sys

import urllib3

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# All paths derive from AUTODEPLOY_ROOT so the tree can be relocated.
AUTODEPLOY_ROOT = os.environ.get("AUTODEPLOY_ROOT", "/srv/autodeploy")
CONFIG_DIR = os.path.join(AUTODEPLOY_ROOT, "config")
LOG_DIR = os.path.join(AUTODEPLOY_ROOT, "logs")
GLOBAL_CONFIG = os.path.join(CONFIG_DIR, "global_config.json")
CREDENTIALS = os.path.join(CONFIG_DIR, "credentials.json")

os.makedirs(LOG_DIR, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, "secure_boot_manager.log")),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger("secure_boot_manager")

try:
    import redfish
except ImportError:  # pragma: no cover - depends on the deployment host
    # Import at module scope used to abort the script with a bare traceback,
    # which surfaced in the admin UI as an unexplained non-zero exit.
    redfish = None

_global_config = None

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

def load_credentials():
    """Load the iLO section of credentials.json."""
    try:
        with open(CREDENTIALS, "r") as f:
            return json.load(f).get("ilo", {})
    except Exception as e:
        logger.error(f"Failed to load credentials from {CREDENTIALS}: {e}")
        return {}


def get_ilo_credentials(mac_address=None):
    """Return (username, password), preferring host-specific overrides.

    toggle_secure_boot() previously ignored the per-host credential block, so
    servers with their own iLO account always failed to authenticate.
    """
    ilo_creds = load_credentials()

    username = ilo_creds.get("admin_user")
    password = ilo_creds.get("admin_password")

    if mac_address:
        host_creds = ilo_creds.get("hosts", {}).get(format_mac(mac_address))
        if host_creds:
            username = host_creds.get("username", username)
            password = host_creds.get("password", password)
            logger.info(f"Using host-specific iLO credentials for {mac_address}")

    return username, password

def hosts_config_path():
    """Absolute path to hosts.json."""
    return load_global_config().get("paths", {}).get(
        "hosts_config", os.path.join(CONFIG_DIR, "hosts.json")
    )


def load_hosts_config():
    """Load the hosts configuration file"""
    hosts_path = hosts_config_path()
    
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
    """Write hosts.json atomically (write to a temp file, then rename)."""
    hosts_path = hosts_config_path()
    tmp_path = f"{hosts_path}.tmp.{os.getpid()}"

    try:
        with open(tmp_path, "w") as f:
            json.dump(hosts_config, f, indent=2)
            f.flush()
            os.fsync(f.fileno())

        os.replace(tmp_path, hosts_path)
        logger.info(f"Hosts configuration saved to {hosts_path}")
    except Exception as e:
        logger.error(f"Failed to save hosts config: {e}")
        if os.path.exists(tmp_path):
            os.unlink(tmp_path)
        sys.exit(1)

def format_mac(mac):
    """Format MAC address consistently"""
    mac = mac.lower().replace(':', '').replace('-', '')
    return ':'.join(mac[i:i+2] for i in range(0, len(mac), 2))

def update_secure_boot(ilo_ip, username, password, enable=True, reset=True):
    """Enable or disable secure boot on an iLO system.

    Returns True when the setting already matched or was applied.
    """
    if redfish is None:
        logger.error("The python 'redfish' module is not installed (pip3 install redfish)")
        return False

    action = "Enabling" if enable else "Disabling"
    desired = "Enabled" if enable else "Disabled"
    redfish_obj = None

    try:
        logger.info(f"{action} secure boot on {ilo_ip}")

        redfish_obj = redfish.RedfishClient(
            base_url=f"https://{ilo_ip}",
            username=username,
            password=password,
            default_prefix="/redfish/v1",
        )
        redfish_obj.login(auth="session")

        bios_data = redfish_obj.get("/Systems/1/Bios").dict
        attributes = bios_data.get("Attributes", {})

        if "SecureBoot" not in attributes:
            logger.error(f"Secure Boot settings not found for {ilo_ip}")
            return False

        current_status = attributes["SecureBoot"]
        logger.info(f"Current secure boot status for {ilo_ip}: {current_status}")

        if current_status == desired:
            logger.info(f"Secure boot already {desired.lower()} on {ilo_ip}; no change needed")
            return True

        update_response = redfish_obj.patch(
            "/Systems/1/Bios/Settings",
            body={"Attributes": {"SecureBoot": desired}},
        )

        # iLO answers 200 or 202 depending on firmware revision.
        if update_response.status not in (200, 202, 204):
            logger.error(
                f"Failed to update secure boot settings for {ilo_ip}: "
                f"{update_response.status} {update_response.text}"
            )
            return False

        if not reset:
            logger.info(f"Secure boot change staged on {ilo_ip}; a reboot is required to apply it")
            return True

        reset_response = redfish_obj.post(
            "/Systems/1/Actions/ComputerSystem.Reset",
            body={"ResetType": "ForceRestart"},
        )

        if reset_response.status not in (200, 202, 204):
            logger.error(
                f"Secure boot setting staged but the reset of {ilo_ip} failed: "
                f"{reset_response.status} {reset_response.text}"
            )
            return False

        logger.info(f"Successfully staged secure boot = {desired} on {ilo_ip} and restarted the system")
        return True

    except Exception as e:
        logger.error(f"Error updating secure boot for {ilo_ip}: {e}")
        return False

    finally:
        # The old code leaked the Redfish session on every error path; iLO
        # allows only a handful of concurrent sessions.
        if redfish_obj is not None:
            try:
                redfish_obj.logout()
            except Exception as e:
                logger.debug(f"Redfish logout for {ilo_ip} failed: {e}")

def find_host_by_mac(mac):
    """Find a host in the configuration by MAC address"""
    hosts_config = load_hosts_config()
    formatted_mac = format_mac(mac)
    
    for host in hosts_config.get("hosts", []):
        if format_mac(host.get("mac_address", "")) == formatted_mac:
            return host
    
    logger.error(f"Host with MAC {mac} not found in configuration")
    return None

def toggle_secure_boot(mac, enable=True, reset=True):
    """Toggle secure boot for a host with the given MAC address"""
    global_config = load_global_config()
    host = find_host_by_mac(mac)

    if not host:
        logger.error(f"Host with MAC {mac} not found")
        return False

    if not host.get("ilo_ip"):
        logger.error(f"Host with MAC {mac} does not have an iLO IP configured")
        return False

    # Per-host overrides take priority over the global iLO account.
    username, password = get_ilo_credentials(mac)
    username = username or global_config.get("ilo", {}).get("admin_user")
    password = password or global_config.get("ilo", {}).get("admin_password")

    if not username or not password:
        logger.error("iLO credentials not found in configuration")
        return False

    result = update_secure_boot(host["ilo_ip"], username, password, enable, reset=reset)

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
    parser.add_argument("--no-reset", action="store_true",
                        help="Stage the BIOS change without rebooting the server")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")

    args = parser.parse_args()

    if args.verbose:
        logger.setLevel(logging.DEBUG)

    result = toggle_secure_boot(args.mac, args.action == "enable", reset=not args.no_reset)
    
    if result:
        print(f"Successfully {args.action}d secure boot for host with MAC {args.mac}")
        return 0
    else:
        print(f"Failed to {args.action} secure boot for host with MAC {args.mac}")
        return 1

if __name__ == "__main__":
    sys.exit(main())