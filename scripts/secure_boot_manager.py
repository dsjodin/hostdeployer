#!/usr/bin/env python3
"""Turn Secure Boot on or off for one host, through its service processor.

The scan stages Secure Boot off across a rack; this is the single-host version
the admin UI drives, and the one that turns it back on when a deployment
finishes.

Both go through redfish_client, which pins the BMC's self-signed certificate.
This used to use the redfish library instead, which meant TLS policy was
decided in two places and the session had to be logged out on every path or the
BMC ran out of slots.
"""

import argparse
import json
import logging
import os
import sys

from autodeploy_api import ApiError, AutodeployApi
from redfish_client import FingerprintMismatch, Redfish, RedfishError

# All paths derive from AUTODEPLOY_ROOT so the tree can be relocated.
AUTODEPLOY_ROOT = os.environ.get("AUTODEPLOY_ROOT", "/srv/autodeploy")
CONFIG_DIR = os.path.join(AUTODEPLOY_ROOT, "config")
LOG_DIR = os.path.join(AUTODEPLOY_ROOT, "logs")
GLOBAL_CONFIG = os.path.join(CONFIG_DIR, "global_config.json")

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

_global_config = None
_api = None


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
    """Return (username, password), preferring host-specific overrides."""
    creds = api().get_credentials("ilo", mac_address)

    return creds.get("admin_user") or creds.get("username"), \
        creds.get("admin_password") or creds.get("password")


def read_secure_boot(rf, system_path):
    """Current state as 'enabled', 'disabled' or 'unknown'."""
    try:
        resource = rf.get(f"{system_path}/SecureBoot")
    except RedfishError:
        resource = None

    if resource and "SecureBootEnable" in resource:
        return "enabled" if resource["SecureBootEnable"] else "disabled"

    try:
        bios = rf.get(f"{system_path}/Bios")
    except RedfishError:
        return "unknown"

    value = (bios or {}).get("Attributes", {}).get("SecureBoot")
    if isinstance(value, bool):
        return "enabled" if value else "disabled"
    if isinstance(value, str):
        return value.lower() if value.lower() in ("enabled", "disabled") else "unknown"

    return "unknown"


def set_secure_boot(rf, system_path, enable):
    """Stage Secure Boot on or off.

    The standard SecureBoot resource first, the vendor BIOS attribute as the
    fallback. Applies at the next POST either way -- neither is a live change.
    """
    try:
        rf.patch(f"{system_path}/SecureBoot", {"SecureBootEnable": bool(enable)})
        return True
    except RedfishError as e:
        logger.debug(f"{rf.address}: SecureBoot resource not writable ({e}); trying BIOS")

    try:
        rf.patch(
            f"{system_path}/Bios/Settings",
            {"Attributes": {"SecureBoot": "Enabled" if enable else "Disabled"}},
        )
        return True
    except RedfishError as e:
        logger.error(f"{rf.address}: could not stage Secure Boot: {e}")
        return False


def connect(host):
    """Open a pinned connection to a host's service processor."""
    address = host.get("ilo_ip")
    if not address:
        raise RedfishError("no iLO address is recorded for this host")

    mac = host.get("mac_address")
    username, password = get_ilo_credentials(mac)

    global_config = load_global_config()
    username = username or global_config.get("ilo", {}).get("admin_user")
    password = password or global_config.get("ilo", {}).get("admin_password")

    if not username or not password:
        raise RedfishError("no iLO credentials are configured")

    return Redfish(
        address, username, password,
        fingerprint=host.get("ilo_cert_sha256") or None,
    )


def toggle_secure_boot(mac, enable=True, reset=True):
    """Set Secure Boot for the host with this MAC, optionally rebooting it."""
    host = api().get_host(mac)

    if host is None:
        logger.error(f"Host with MAC {mac} not found in the inventory")
        return False

    try:
        rf = connect(host)
        system_path = rf.system_path()

        desired = "enabled" if enable else "disabled"
        current = read_secure_boot(rf, system_path)

        if current == desired:
            logger.info(f"{rf.address}: Secure Boot already {desired}; no change needed")
            api().set_secure_boot_status(mac, desired)
            return True

        if not set_secure_boot(rf, system_path, enable):
            return False

        if reset:
            rf.post(
                f"{system_path}/Actions/ComputerSystem.Reset",
                {"ResetType": "ForceRestart"},
            )
            logger.info(f"{rf.address}: staged Secure Boot {desired} and restarted")
        else:
            logger.info(f"{rf.address}: staged Secure Boot {desired}; a reboot will apply it")

        api().set_secure_boot_status(mac, desired)
        return True

    except FingerprintMismatch as e:
        logger.error(str(e))
        return False
    except RedfishError as e:
        logger.error(f"Secure Boot change for {mac} failed: {e}")
        return False


def main():
    parser = argparse.ArgumentParser(description="Manage Secure Boot for one host")
    parser.add_argument("--mac", required=True, help="MAC address of the target server")
    parser.add_argument("--action", required=True, choices=["enable", "disable"],
                        help="Action to perform")
    parser.add_argument("--no-reset", action="store_true",
                        help="Stage the change without rebooting the server")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")

    args = parser.parse_args()

    if args.verbose:
        logger.setLevel(logging.DEBUG)

    if toggle_secure_boot(args.mac, args.action == "enable", reset=not args.no_reset):
        print(f"Successfully {args.action}d Secure Boot for host with MAC {args.mac}")
        return 0

    print(f"Failed to {args.action} Secure Boot for host with MAC {args.mac}")
    return 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ApiError as e:
        logger.error(str(e))
        sys.exit(1)
