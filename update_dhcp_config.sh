#!/bin/bash
# /usr/local/bin/update_dhcp_config.sh

# This script safely updates the DHCP configuration and restarts the service
# Only accepts parameters in a specific format to enhance security

# Check if correct number of parameters
if [ "$#" -ne 6 ]; then
    echo "ERROR: Incorrect number of parameters"
    echo "Usage: $0 dhcp_start dhcp_end subnet_mask gateway dns_servers webserver_ip"
    exit 1
fi

# Assign parameters to variables
DHCP_START="$1"
DHCP_END="$2"
SUBNET_MASK="$3"
GATEWAY="$4"
DNS_SERVERS="$5"
WEBSERVER_IP="$6"

# Validate IP addresses (basic validation)
validate_ip() {
    if [[ ! $1 =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
        return 1
    fi
    return 0
}

# Validate all IP addresses
if ! validate_ip "$DHCP_START" || ! validate_ip "$DHCP_END" || \
   ! validate_ip "$GATEWAY" || ! validate_ip "$WEBSERVER_IP"; then
    echo "ERROR: Invalid IP address format"
    exit 1
fi

# Validate subnet mask
if [[ ! $SUBNET_MASK =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
    echo "ERROR: Invalid subnet mask format"
    exit 1
fi

# Determine subnet
IFS=. read -r i1 i2 i3 i4 <<< "$DHCP_START"
SUBNET="${i1}.${i2}.${i3}.0"

# Create backup
BACKUP_FILE="/etc/dhcp/dhcpd.conf.bak.$(date +%Y%m%d%H%M%S)"
cp /etc/dhcp/dhcpd.conf "$BACKUP_FILE"
if [ $? -ne 0 ]; then
    echo "WARNING: Failed to create backup of DHCP configuration"
fi

# Generate new configuration
cat > /etc/dhcp/dhcpd.conf << EOF
option domain-name "local";
option domain-name-servers $DNS_SERVERS;
default-lease-time 600;
max-lease-time 7200;
authoritative;

# For detecting client architecture
option arch code 93 = unsigned integer 16;

# Detect iPXE and chain to boot.ipxe script
if exists user-class and option user-class = "iPXE" {
  filename "http://$WEBSERVER_IP/ipxe/boot.ipxe";
} else {
  # UEFI clients
  if option arch = 00:07 or option arch = 00:09 {
    filename "http://$WEBSERVER_IP/ipxe/ipxe.efi";
  } else {
    # Legacy BIOS clients (if needed)
    filename "http://$WEBSERVER_IP/ipxe/ipxe.pxe";
  }
}

subnet $SUBNET netmask $SUBNET_MASK {
  range $DHCP_START $DHCP_END;
  option routers $GATEWAY;
}
EOF

# Check if configuration file was written successfully
if [ $? -ne 0 ]; then
    echo "ERROR: Failed to write DHCP configuration file"
    exit 1
fi

# Validate configuration before restarting
dhcpd -t -cf /etc/dhcp/dhcpd.conf
if [ $? -ne 0 ]; then
    echo "ERROR: Invalid DHCP configuration. Restoring backup."
    cp "$BACKUP_FILE" /etc/dhcp/dhcpd.conf
    exit 1
fi

# Restart DHCP service
systemctl restart isc-dhcp-server
if [ $? -ne 0 ]; then
    echo "ERROR: Failed to restart DHCP service"
    exit 1
fi

echo "SUCCESS: DHCP configuration updated and service restarted"
exit 0
