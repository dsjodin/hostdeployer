#!/usr/bin/env python3
"""Boot one host from the network, once, now.

What an operator means by "deploy this host": set the next boot to the network
and restart it. Everything after that is the boot chain's job.

The target is "Pxe" rather than "UefiHttp" with an explicit URL. Pxe is
implemented by every service processor and needs no capability check, and the
firmware's choice between its HTTP Boot and PXE entries does not matter: Kea
serves the same ipxe.efi to both classes. Naming a URL here would mean checking
BootSourceOverrideTarget@Redfish.AllowableValues, carrying a BIOS-attribute
fallback for firmware that lacks UefiHttp, and owning a second copy of the
boot URL that DHCP already owns.

"Once" rather than "Continuous": iPXE stays resident and re-chains over HTTP
while it waits for approval, so it never re-enters firmware boot selection and
never burns the override. A host that gives up and falls through to local disk
gets a fresh override the next time an operator asks for one.
"""

import argparse
import json
import logging
import os
import sys

from autodeploy_api import ApiError, AutodeployApi
from redfish_client import FingerprintMismatch, Redfish, RedfishError

AUTODEPLOY_ROOT = os.environ.get("AUTODEPLOY_ROOT", "/srv/autodeploy")
CONFIG_DIR = os.path.join(AUTODEPLOY_ROOT, "config")
LOG_DIR = os.path.join(AUTODEPLOY_ROOT, "logs")
GLOBAL_CONFIG = os.path.join(CONFIG_DIR, "global_config.json")

os.makedirs(LOG_DIR, exist_ok=True)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, "network_boot.log")),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger("network_boot")

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


def connect(host):
    """Open a pinned connection to a host's service processor."""
    address = host.get("ilo_ip")
    if not address:
        raise RedfishError("no iLO address is recorded for this host")

    creds = api().get_credentials("ilo", host.get("mac_address"))
    username = creds.get("admin_user") or creds.get("username")
    password = creds.get("admin_password") or creds.get("password")

    global_config = load_global_config()
    username = username or global_config.get("ilo", {}).get("admin_user")
    password = password or global_config.get("ilo", {}).get("admin_password")

    if not username or not password:
        raise RedfishError("no iLO credentials are configured")

    return Redfish(
        address, username, password,
        fingerprint=host.get("ilo_cert_sha256") or None,
    )


def boot_from_network(mac, reset=True):
    """Set a one-time network boot on this host and restart it."""
    host = api().get_host(mac)

    if host is None:
        logger.error(f"Host with MAC {mac} not found in the inventory")
        return False

    if host.get("secure_boot_status") == "enabled":
        # ipxe.efi is unsigned, so the firmware will refuse it and the host
        # will fall through to its next boot device. Rebooting a machine into
        # a boot that cannot succeed is worse than refusing here.
        logger.error(
            f"{mac} still has Secure Boot enabled; ipxe.efi will not load. "
            "Disable it first (scripts/secure_boot_manager.py --action disable)."
        )
        return False

    try:
        rf = connect(host)
        system_path = rf.system_path()

        rf.patch(system_path, {
            "Boot": {
                "BootSourceOverrideTarget": "Pxe",
                "BootSourceOverrideEnabled": "Once",
            },
        })
        logger.info(f"{rf.address}: next boot set to the network")

        if reset:
            # On rather than ForceRestart: a machine that has never been
            # powered up has nothing to restart, and iLO answers a reset of a
            # powered-off system with an error.
            state = (rf.get(system_path) or {}).get("PowerState")
            reset_type = "ForceRestart" if state == "On" else "On"

            rf.post(
                f"{system_path}/Actions/ComputerSystem.Reset",
                {"ResetType": reset_type},
            )
            logger.info(f"{rf.address}: {reset_type} issued (power state was {state})")

        return True

    except FingerprintMismatch as e:
        logger.error(str(e))
        return False
    except RedfishError as e:
        logger.error(f"Network boot for {mac} failed: {e}")
        return False


def main():
    parser = argparse.ArgumentParser(description="Boot one host from the network, once")
    parser.add_argument("--mac", required=True, help="MAC address of the target server")
    parser.add_argument("--no-reset", action="store_true",
                        help="Set the override without powering the host")
    parser.add_argument("--verbose", action="store_true", help="Enable debug logging")

    args = parser.parse_args()

    if args.verbose:
        logger.setLevel(logging.DEBUG)

    if boot_from_network(args.mac, reset=not args.no_reset):
        print(f"Host {args.mac} will boot from the network")
        return 0

    print(f"Could not set a network boot for {args.mac}")
    return 1


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ApiError as e:
        logger.error(str(e))
        sys.exit(1)
