#!/usr/bin/env python3
"""Discover service processors on the BMC network and register what they run.

This is where a server enters the inventory. It sweeps the iLO/iDRAC network,
asks every card that answers for its serial number, its host NIC MAC addresses
and its Secure Boot state, and stages Secure Boot off so the unsigned ipxe.efi
can load when the machine is eventually booted.

The address is not the identity. Infoblox owns the BMC network and a card can
come back on a different address, so each one is reverse-resolved and stored by
name. The name also carries the machine's identity -- orbesx1001-ilo.dc.infra
belongs to orbesx1001 -- which is where a discovered host gets its hostname.

The join to the boot chain is the serial number. A booting host reports its
MAC and its SMBIOS serial, and the MAC a machine PXE boots from is not
necessarily the one the BMC lists first. Serial survives a NIC swap, a
re-cabling and a firmware upgrade; it is what boot.ipxe.php matches on.
"""

import argparse
import ipaddress
import json
import logging
import os
import socket
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

from autodeploy_api import ApiError, AutodeployApi
from redfish_client import FingerprintMismatch, Redfish, RedfishError

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
    """Normalise a MAC to lowercase colon-separated form."""
    mac = str(mac).lower().replace(":", "").replace("-", "")
    return ":".join(mac[i:i + 2] for i in range(0, len(mac), 2))


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


def check_host_reachable(ip):
    """Whether an address answers a single ping."""
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


def reverse_resolve(ip):
    """The PTR name for an address, or None when there is not one.

    Infoblox allocates the BMC addresses and registers the names, so the PTR
    is the authoritative link between an address and a machine. Falling back to
    the raw address is correct but lossy: nothing downstream can then derive a
    hostname, and the record breaks the next time the card is re-addressed.
    """
    try:
        name, _, _ = socket.gethostbyaddr(ip)
    except OSError:
        logger.debug(f"No PTR record for {ip}")
        return None

    return name.rstrip(".").lower() or None


def hostname_from_bmc_name(bmc_name, suffix):
    """Derive the machine's hostname from its BMC's name.

    orbesx1001-ilo.dc.infra -> orbesx1001, given a suffix of "-ilo".

    Returns None when the name does not carry the suffix, rather than guessing.
    A wrong hostname here reaches the kickstart.
    """
    if not bmc_name:
        return None

    label = bmc_name.split(".")[0]

    if suffix and label.endswith(suffix):
        return label[:-len(suffix)] or None

    return None


def collect_mac_addresses(rf, system_path):
    """Every host NIC MAC the BMC will report.

    Two collections because they are not equally populated across vendors and
    firmware revisions: EthernetInterfaces is the one that carries MACAddress
    directly, NetworkInterfaces has to be walked into its adapter ports. Both
    are tried and the results merged, because a card that reports partial
    information under one is common enough to have cost a scan before.
    """
    macs = []

    ethernet = rf.get(f"{system_path}/EthernetInterfaces")
    for member in (ethernet or {}).get("Members", []):
        path = member.get("@odata.id")
        if not path:
            continue
        try:
            interface = rf.get(path)
        except RedfishError as e:
            logger.debug(f"{rf.address}: {path} unreadable: {e}")
            continue
        mac = (interface or {}).get("MACAddress") or (interface or {}).get("MacAddress")
        if mac:
            macs.append(format_mac(mac))

    if not macs:
        network = rf.get(f"{system_path}/NetworkInterfaces")
        for member in (network or {}).get("Members", []):
            path = member.get("@odata.id")
            if not path:
                continue
            try:
                adapter = rf.get(path)
            except RedfishError as e:
                logger.debug(f"{rf.address}: {path} unreadable: {e}")
                continue
            for key in ("MACAddress", "MacAddress"):
                if (adapter or {}).get(key):
                    macs.append(format_mac(adapter[key]))

    # Order matters only in that the first one becomes the record's primary
    # MAC; the rest are stored as secondaries and match just as well.
    seen = set()
    unique = []
    for mac in macs:
        if mac and mac not in seen:
            seen.add(mac)
            unique.append(mac)

    return unique


def read_secure_boot(rf, system_path):
    """Current Secure Boot state as 'enabled', 'disabled' or 'unknown'.

    Lowercase because that is what the inventory and the admin UI compare
    against. The previous scanner returned "Enabled", which matched nothing and
    left every scanned host displaying an unknown state.
    """
    try:
        resource = rf.get(f"{system_path}/SecureBoot")
    except RedfishError as e:
        logger.debug(f"{rf.address}: SecureBoot resource unreadable: {e}")
        resource = None

    if resource:
        if "SecureBootEnable" in resource:
            return "enabled" if resource["SecureBootEnable"] else "disabled"
        if resource.get("SecureBootCurrentBoot"):
            return "enabled" if resource["SecureBootCurrentBoot"] == "Enabled" else "disabled"

    try:
        bios = rf.get(f"{system_path}/Bios")
    except RedfishError as e:
        logger.debug(f"{rf.address}: BIOS attributes unreadable: {e}")
        return "unknown"

    attributes = (bios or {}).get("Attributes", {})
    for key in ("SecureBoot", "SecureBootEnable", "SecureBootStatus"):
        if key not in attributes:
            continue
        value = attributes[key]
        if isinstance(value, bool):
            return "enabled" if value else "disabled"
        if isinstance(value, str):
            if value.lower() in ("enabled", "enable", "on", "true", "yes", "1"):
                return "enabled"
            if value.lower() in ("disabled", "disable", "off", "false", "no", "0"):
                return "disabled"

    return "unknown"


def stage_secure_boot_off(rf, system_path):
    """Stage Secure Boot off, to take effect at the machine's next POST.

    Nothing is rebooted here. The scan runs across a live BMC network and a
    reset issued by a discovery sweep is the one mistake in this tool that
    cannot be undone.

    The standard SecureBoot resource is tried first because both iLO and iDRAC
    implement it; the vendor BIOS attribute is the fallback for firmware that
    does not.
    """
    try:
        rf.patch(f"{system_path}/SecureBoot", {"SecureBootEnable": False})
        return True
    except RedfishError as e:
        logger.debug(f"{rf.address}: SecureBoot resource not writable ({e}); trying BIOS")

    try:
        rf.patch(f"{system_path}/Bios/Settings", {"Attributes": {"SecureBoot": "Disabled"}})
        return True
    except RedfishError as e:
        logger.warning(f"{rf.address}: could not stage Secure Boot off: {e}")
        return False


def scan_bmc(address, username, password, fingerprint=None, disable_secure_boot=False):
    """Interrogate one service processor.

    @return dict for storeMergeDiscoveredHosts(), or None
    """
    try:
        rf = Redfish(address, username, password, fingerprint=fingerprint)
    except FingerprintMismatch as e:
        # Never downgraded to a warning and never re-pinned: a BMC presenting a
        # different certificate is either a replaced card or something else
        # answering on its address, and both need a person.
        logger.error(str(e))
        return None
    except RedfishError as e:
        logger.debug(f"{address}: {e}")
        return None

    try:
        system_path = rf.system_path()
        system = rf.get(system_path) or {}
    except RedfishError as e:
        logger.warning(f"{address}: {e}")
        return None

    serial = str(system.get("SerialNumber") or "").strip()
    macs = collect_mac_addresses(rf, system_path)
    secure_boot = read_secure_boot(rf, system_path)

    if disable_secure_boot and secure_boot != "disabled":
        if stage_secure_boot_off(rf, system_path):
            logger.info(f"{address}: staged Secure Boot off, applies at next POST")
            secure_boot = "disabled"

    result = {
        "ilo_ip": address,
        "serial_number": serial,
        "mac_address": macs[0] if macs else "",
        "additional_macs": macs[1:],
        "model": system.get("Model") or "",
        "manufacturer": system.get("Manufacturer") or "",
        "bios_version": system.get("BiosVersion") or "",
        "secure_boot_status": secure_boot,
        "ilo_cert_sha256": rf.fingerprint,
        "deployment_status": "pending",
    }

    logger.info(
        f"{address}: {result['manufacturer']} {result['model']} "
        f"S/N {serial or 'unknown'}, {len(macs)} MAC(s), Secure Boot {secure_boot}"
    )

    return result


def scan_address(ip, username, password, known, suffix, disable_secure_boot):
    """Resolve, identify and record one address from the sweep."""
    if not check_host_reachable(ip):
        return None

    bmc_name = reverse_resolve(ip)
    address = bmc_name or ip

    if bmc_name is None:
        logger.warning(f"{ip} has no PTR record; storing it by address")

    # The pinned fingerprint, when this card has been seen before. Looked up by
    # the name it is stored under, which is why the PTR lookup happens first.
    previous = known.get(address, {})

    result = scan_bmc(
        address,
        username,
        password,
        fingerprint=previous.get("ilo_cert_sha256") or None,
        # A machine that is already running ESXi must not have its BIOS
        # touched by a discovery sweep. The BMC network carries production.
        disable_secure_boot=disable_secure_boot
        and previous.get("deployment_status") not in ("deployed", "deploying"),
    )

    if result is None:
        return None

    hostname = hostname_from_bmc_name(bmc_name, suffix)
    if hostname:
        result["hostname"] = hostname

    return result


def sweep(start_ip, end_ip, username, password, known, suffix,
          disable_secure_boot, max_threads=16):
    """Scan a range of addresses on the BMC network."""
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
    logger.info(f"Scanning {len(ip_list)} addresses from {start_ip} to {end_ip}")

    results = []
    with ThreadPoolExecutor(max_workers=max_threads) as executor:
        futures = {
            executor.submit(
                scan_address, ip, username, password, known, suffix, disable_secure_boot
            ): ip
            for ip in ip_list
        }

        # as_completed() so a slow host does not stall the rest, and each result
        # is unwrapped in its own try: one raising future used to abort the
        # entire scan and discard every host found so far.
        for future in as_completed(futures):
            ip = futures[future]
            try:
                result = future.result()
            except Exception as e:
                logger.warning(f"Scan of {ip} raised an error: {e}")
                continue

            if not result:
                continue

            # Re-read with host-specific credentials when the estate account is
            # not the one this machine uses.
            mac = result.get("mac_address")
            if mac:
                host_username, host_password = get_ilo_credentials(mac)
                if host_username and (host_username, host_password) != (username, password):
                    logger.info(f"Re-reading {result['ilo_ip']} with host-specific credentials")
                    try:
                        retry = scan_bmc(
                            result["ilo_ip"], host_username, host_password,
                            fingerprint=result.get("ilo_cert_sha256"),
                        )
                    except Exception as e:
                        logger.warning(f"Re-read of {result['ilo_ip']} failed: {e}")
                        retry = None

                    if retry:
                        retry.setdefault("hostname", result.get("hostname", ""))
                        results.append(retry)
                        continue

            results.append(result)

    logger.info(f"Scan complete. Found {len(results)} service processors")
    return results


def known_bmcs():
    """What the inventory already records, keyed by BMC address.

    Two things come from here: the pinned certificate for a card that has been
    seen before, and whether the machine behind it is already deployed.
    """
    index = {}

    for host in api().get_hosts():
        address = host.get("ilo_ip")
        if address:
            index[str(address).lower()] = host

    return index


def update_hosts_config(scan_results):
    """Send scan results to the API, which merges them into the inventory."""
    return api().merge_discovered(scan_results)


def main():
    parser = argparse.ArgumentParser(
        description="Discover iLO/iDRAC service processors on the BMC network"
    )
    parser.add_argument("--start", help="First address to scan (defaults to global_config.json)")
    parser.add_argument("--end", help="Last address to scan (defaults to global_config.json)")
    parser.add_argument("--threads", type=int, default=16, help="Concurrent scans (default: 16)")
    parser.add_argument("--dry-run", action="store_true", help="Scan but do not update the inventory")
    parser.add_argument(
        "--keep-secure-boot", action="store_true",
        help="Report Secure Boot state without staging it off",
    )
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
        logger.error("No BMC scan range configured; set ilo.scan_range_start and ilo.scan_range_end")
        return 1

    suffix = ilo_config.get("name_suffix", "-ilo")
    disable_secure_boot = not args.keep_secure_boot

    if disable_secure_boot:
        logger.info(
            "Secure Boot will be staged off on discovered machines that are not "
            "already deployed; nothing is rebooted by this scan"
        )

    results = sweep(
        start_ip, end_ip, username, password,
        known=known_bmcs(),
        suffix=suffix,
        disable_secure_boot=disable_secure_boot,
        max_threads=args.threads,
    )

    if args.dry_run:
        print(f"Scan complete (dry run). Found {len(results)}; the inventory was not modified.")
        return 0

    updated, added = update_hosts_config(results)

    print(f"Scan complete. Found {len(results)} service processors.")
    print(f"Updated {updated} existing entries and added {added} new entries.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except ApiError as e:
        logger.error(str(e))
        sys.exit(1)
