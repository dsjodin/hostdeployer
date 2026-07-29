#!/bin/bash
# /usr/local/bin/update_dhcp_config.sh
#
# Regenerate the DHCP server configuration and reload the service.
#
# Invoked from the admin UI through a restricted sudo rule, so it must
# validate every argument itself and never interpolate untrusted text into a
# shell command.
#
# Usage: update_dhcp_config.sh <start> <end> <netmask> <gateway> <dns> <server-ip>
#
# Set DHCP_BACKEND=kea to write a Kea DHCPv4 configuration instead of the
# legacy ISC dhcpd one.

set -euo pipefail

DHCP_BACKEND="${DHCP_BACKEND:-isc}"
KEEP_BACKUPS="${KEEP_BACKUPS:-10}"

die() {
    echo "ERROR: $*" >&2
    exit 1
}

if [ "$#" -ne 6 ]; then
    echo "Usage: $0 dhcp_start dhcp_end subnet_mask gateway dns_servers webserver_ip" >&2
    die "Incorrect number of parameters"
fi

DHCP_START="$1"
DHCP_END="$2"
SUBNET_MASK="$3"
GATEWAY="$4"
DNS_SERVERS="$5"
WEBSERVER_IP="$6"

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------

# The old regex accepted things like 999.999.999.999, and the DNS list was
# never validated at all even though it is written straight into the config.
valid_ip() {
    local ip="$1" o1 o2 o3 o4
    [[ $ip =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    o1=${BASH_REMATCH[1]}; o2=${BASH_REMATCH[2]}; o3=${BASH_REMATCH[3]}; o4=${BASH_REMATCH[4]}
    (( o1 <= 255 && o2 <= 255 && o3 <= 255 && o4 <= 255 )) || return 1
    return 0
}

ip_to_int() {
    local IFS=. a b c d
    read -r a b c d <<< "$1"
    echo $(( (a << 24) + (b << 16) + (c << 8) + d ))
}

int_to_ip() {
    local i="$1"
    echo "$(( (i >> 24) & 255 )).$(( (i >> 16) & 255 )).$(( (i >> 8) & 255 )).$(( i & 255 ))"
}

for pair in "start:$DHCP_START" "end:$DHCP_END" "gateway:$GATEWAY" "webserver:$WEBSERVER_IP" "netmask:$SUBNET_MASK"; do
    valid_ip "${pair#*:}" || die "Invalid ${pair%%:*} address: ${pair#*:}"
done

# A netmask must be a contiguous run of ones.
MASK_INT=$(ip_to_int "$SUBNET_MASK")
INVERTED=$(( (~MASK_INT) & 0xFFFFFFFF ))
if [ $(( (INVERTED + 1) & INVERTED )) -ne 0 ]; then
    die "Invalid subnet mask: $SUBNET_MASK"
fi

START_INT=$(ip_to_int "$DHCP_START")
END_INT=$(ip_to_int "$DHCP_END")
(( START_INT <= END_INT )) || die "DHCP range start is higher than the range end"

# Both ends of the pool must live in the same subnet as the gateway.
GATEWAY_INT=$(ip_to_int "$GATEWAY")
NETWORK_INT=$(( START_INT & MASK_INT ))
(( (END_INT & MASK_INT) == NETWORK_INT )) || die "DHCP range spans more than one subnet"
(( (GATEWAY_INT & MASK_INT) == NETWORK_INT )) || die "Gateway $GATEWAY is outside the DHCP subnet"

# Validate every DNS server in the comma-separated list.
IFS=',' read -ra DNS_LIST <<< "$DNS_SERVERS"
[ "${#DNS_LIST[@]}" -gt 0 ] || die "At least one DNS server is required"

DNS_CLEAN=""
for dns in "${DNS_LIST[@]}"; do
    dns="${dns// /}"
    [ -n "$dns" ] || continue
    valid_ip "$dns" || die "Invalid DNS server address: $dns"
    DNS_CLEAN="${DNS_CLEAN:+$DNS_CLEAN, }$dns"
done
[ -n "$DNS_CLEAN" ] || die "At least one DNS server is required"

SUBNET=$(int_to_ip "$NETWORK_INT")
# Count the set bits in the mask to get the prefix length. Written with
# explicit assignments rather than (( ... )) statements because an arithmetic
# command that evaluates to zero returns exit status 1, which trips set -e.
PREFIX_LEN=0
tmp=$MASK_INT
while [ "$tmp" -ne 0 ]; do
    PREFIX_LEN=$(( PREFIX_LEN + (tmp & 1) ))
    tmp=$(( (tmp >> 1) & 0x7FFFFFFF ))
done

# ---------------------------------------------------------------------------
# Backend configuration
# ---------------------------------------------------------------------------

case "$DHCP_BACKEND" in
    kea)
        CONFIG_FILE="/etc/kea/kea-dhcp4.conf"
        SERVICE="kea-dhcp4-server"
        VALIDATE_CMD=(kea-dhcp4 -t)
        ;;
    isc)
        CONFIG_FILE="/etc/dhcp/dhcpd.conf"
        SERVICE="isc-dhcp-server"
        VALIDATE_CMD=(dhcpd -t -cf)
        ;;
    *)
        die "Unknown DHCP_BACKEND '$DHCP_BACKEND' (expected 'isc' or 'kea')"
        ;;
esac

[ -w "$(dirname "$CONFIG_FILE")" ] || die "Cannot write to $(dirname "$CONFIG_FILE")"

BACKUP_FILE=""
if [ -f "$CONFIG_FILE" ]; then
    BACKUP_FILE="${CONFIG_FILE}.bak.$(date +%Y%m%d%H%M%S)"
    cp -p "$CONFIG_FILE" "$BACKUP_FILE" || die "Failed to back up $CONFIG_FILE"
fi

# Build into a temporary file first; only a validated config is installed.
NEW_CONFIG="$(mktemp "${CONFIG_FILE}.new.XXXXXX")"

cleanup() {
    rm -f "$NEW_CONFIG"
}
trap cleanup EXIT

if [ "$DHCP_BACKEND" = "kea" ]; then
    KEA_DNS="${DNS_CLEAN// /}"
    cat > "$NEW_CONFIG" <<EOF
{
"Dhcp4": {
    "interfaces-config": { "interfaces": [ "*" ] },
    "control-socket": {
        "socket-type": "unix",
        "socket-name": "/run/kea/kea4-ctrl-socket"
    },
    "lease-database": {
        "type": "memfile",
        "lfc-interval": 3600,
        "name": "/var/lib/kea/kea-leases4.csv"
    },
    "valid-lifetime": 600,
    "max-valid-lifetime": 7200,
    "client-classes": [
        {
            "name": "iPXE",
            "test": "substring(option[77].hex, 0, 4) == 'iPXE'",
            "boot-file-name": "http://$WEBSERVER_IP/ipxe/boot.ipxe"
        },
        {
            "name": "UEFI-HTTP",
            "test": "not member('iPXE') and substring(option[60].hex, 0, 10) == 'HTTPClient'",
            "option-data": [
                { "name": "vendor-class-identifier", "data": "HTTPClient" }
            ],
            "boot-file-name": "http://$WEBSERVER_IP/ipxe/ipxe.efi"
        },
        {
            "name": "UEFI-PXE",
            "test": "not member('iPXE') and (option[93].hex == 0x0007 or option[93].hex == 0x0009 or option[93].hex == 0x000b)",
            "next-server": "$WEBSERVER_IP",
            "boot-file-name": "ipxe.efi"
        }
    ],
    "subnet4": [
        {
            "id": 1,
            "subnet": "$SUBNET/$PREFIX_LEN",
            "pools": [ { "pool": "$DHCP_START - $DHCP_END" } ],
            "next-server": "$WEBSERVER_IP",
            "option-data": [
                { "name": "routers", "data": "$GATEWAY" },
                { "name": "domain-name-servers", "data": "$KEA_DNS" }
            ]
        }
    ]
}
}
EOF
else
    cat > "$NEW_CONFIG" <<EOF
# Generated by update_dhcp_config.sh on $(date '+%Y-%m-%d %H:%M:%S')
# Manual edits will be overwritten.

option domain-name "local";
option domain-name-servers $DNS_CLEAN;
default-lease-time 600;
max-lease-time 7200;
authoritative;
log-facility local7;

option arch code 93 = unsigned integer 16;

subnet $SUBNET netmask $SUBNET_MASK {
  range $DHCP_START $DHCP_END;
  option routers $GATEWAY;
  next-server $WEBSERVER_IP;

  if exists user-class and option user-class = "iPXE" {
    filename "http://$WEBSERVER_IP/ipxe/boot.ipxe";

  } elsif substring(option vendor-class-identifier, 0, 10) = "HTTPClient" {
    option vendor-class-identifier "HTTPClient";
    filename "http://$WEBSERVER_IP/ipxe/ipxe.efi";

  } elsif option arch = 00:07 or option arch = 00:09 or option arch = 00:0b {
    filename "ipxe.efi";
  }
}
EOF
fi

# ---------------------------------------------------------------------------
# Validate, install, reload
# ---------------------------------------------------------------------------

if ! "${VALIDATE_CMD[@]}" "$NEW_CONFIG" >/dev/null 2>&1; then
    echo "--- rejected configuration ---" >&2
    "${VALIDATE_CMD[@]}" "$NEW_CONFIG" >&2 || true
    die "Generated DHCP configuration is invalid; the running config was left untouched"
fi

install -m 0644 "$NEW_CONFIG" "$CONFIG_FILE" || die "Failed to install $CONFIG_FILE"

if ! systemctl restart "$SERVICE"; then
    if [ -n "$BACKUP_FILE" ]; then
        echo "WARNING: restart failed, restoring the previous configuration" >&2
        cp -p "$BACKUP_FILE" "$CONFIG_FILE"
        systemctl restart "$SERVICE" || true
    fi
    die "Failed to restart $SERVICE"
fi

# Keep the backup directory from growing without bound.
if [ -n "$BACKUP_FILE" ]; then
    # shellcheck disable=SC2012
    ls -1t "${CONFIG_FILE}".bak.* 2>/dev/null | tail -n "+$((KEEP_BACKUPS + 1))" | xargs -r rm -f
fi

echo "SUCCESS: $DHCP_BACKEND configuration updated and $SERVICE restarted"
