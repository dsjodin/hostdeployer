#!/bin/bash
# Install hostdeployer on Debian 13 (trixie).
#
# Everything the README describes as a manual step, done once and idempotently:
# packages, the tree under /srv/autodeploy, PHP-FPM, nginx with a certificate,
# Kea DHCPv4, the secrets and the API token.
#
# Debian 13 ships PHP 8.4, nginx 1.26+ and Kea 2.6 in main, so nothing here
# adds a third-party repository. That is the reason for pinning to trixie
# rather than supporting a range of releases: on bookworm every one of those
# would need sury.org, and an appliance that hands out root passwords is not
# where you want an unattended third-party apt source.
#
# Safe to re-run. Existing secrets, configuration and the host inventory are
# never overwritten -- the script says what it skipped and why.
#
#   sudo ./install.sh --server-ip 10.0.0.2 --interface ens192 \
#        --dhcp-range 10.0.0.100-10.0.0.200 --netmask 255.255.255.0 \
#        --gateway 10.0.0.1 --dns 10.0.0.53
#
# Run with no arguments on a terminal and it asks.

set -euo pipefail

readonly ROOT=/srv/autodeploy
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SRC
readonly CERT_DIR=/etc/ssl/autodeploy
readonly PHP_VERSION=8.4

# --------------------------------------------------------------------------
# Output
# --------------------------------------------------------------------------

if [ -t 1 ]; then
    readonly C_OK=$'\033[32m' C_WARN=$'\033[33m' C_ERR=$'\033[31m' C_DIM=$'\033[2m' C_OFF=$'\033[0m'
else
    readonly C_OK='' C_WARN='' C_ERR='' C_DIM='' C_OFF=''
fi

step()  { printf '\n%s==>%s %s\n' "$C_OK" "$C_OFF" "$*"; }
info()  { printf '    %s\n' "$*"; }
skip()  { printf '    %sskipped: %s%s\n' "$C_DIM" "$*" "$C_OFF"; }
warn()  { printf '    %swarning: %s%s\n' "$C_WARN" "$*" "$C_OFF"; }
die()   { printf '\n%serror:%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }

# Collected and printed at the end, so a thing needing attention is not lost
# in several hundred lines of apt output.
NOTES=()
note() { NOTES+=("$*"); }

# --------------------------------------------------------------------------
# Arguments
# --------------------------------------------------------------------------

SERVER_IP=""
INTERFACE=""
DHCP_START=""
DHCP_END=""
NETMASK="255.255.255.0"
GATEWAY=""
DNS=""
NTP=""
DOMAIN="local"
ADMIN_PASSWORD=""
ILO_USER="Administrator"
ILO_PASSWORD=""
ESXI_PASSWORD=""
ASSUME_YES=0
SKIP_PACKAGES=0

usage() {
    sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    cat <<'USAGE'

Options:
  --server-ip ADDR        This server's address on the provisioning network
  --interface NAME        Interface Kea listens on
  --dhcp-range A-B        Address pool handed to booting servers
  --netmask MASK          Provisioning network mask (default 255.255.255.0)
  --gateway ADDR          Gateway offered to booting servers
  --dns ADDR[,ADDR]       DNS servers offered to booting servers
  --ntp ADDR[,ADDR]       NTP servers written into the kickstart
  --domain NAME           Search domain (default: local)
  --admin-password PASS   Dashboard password (generated if omitted)
  --ilo-user NAME         iLO account (default: Administrator)
  --ilo-password PASS     iLO password
  --esxi-password PASS    Root password every host is installed with
  --skip-packages         Do not touch apt; assume everything is installed
  --yes                   Do not prompt; fail if something required is missing
  --help                  This text
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --server-ip)       SERVER_IP="${2:-}"; shift 2 ;;
        --interface)       INTERFACE="${2:-}"; shift 2 ;;
        --dhcp-range)      DHCP_START="${2%%-*}"; DHCP_END="${2##*-}"; shift 2 ;;
        --netmask)         NETMASK="${2:-}"; shift 2 ;;
        --gateway)         GATEWAY="${2:-}"; shift 2 ;;
        --dns)             DNS="${2:-}"; shift 2 ;;
        --ntp)             NTP="${2:-}"; shift 2 ;;
        --domain)          DOMAIN="${2:-}"; shift 2 ;;
        --admin-password)  ADMIN_PASSWORD="${2:-}"; shift 2 ;;
        --ilo-user)        ILO_USER="${2:-}"; shift 2 ;;
        --ilo-password)    ILO_PASSWORD="${2:-}"; shift 2 ;;
        --esxi-password)   ESXI_PASSWORD="${2:-}"; shift 2 ;;
        --skip-packages)   SKIP_PACKAGES=1; shift ;;
        --yes|-y)          ASSUME_YES=1; shift ;;
        --help|-h)         usage; exit 0 ;;
        *)                 die "Unknown option: $1 (try --help)" ;;
    esac
done

# --------------------------------------------------------------------------
# Validation helpers
# --------------------------------------------------------------------------

valid_ip() {
    local ip="$1" o
    [[ $ip =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    for o in "${BASH_REMATCH[@]:1:4}"; do
        (( o <= 255 )) || return 1
    done
}

valid_ip_list() {
    local IFS=',' item
    for item in $1; do
        valid_ip "${item// /}" || return 1
    done
    [ -n "$1" ]
}

ip_to_int() {
    local IFS=. a b c d
    read -r a b c d <<< "$1"
    echo $(( (a << 24) + (b << 16) + (c << 8) + d ))
}

# Ask for a value, or fail if there is nobody to ask.
prompt() {
    local var="$1" question="$2" default="${3:-}" secret="${4:-0}" answer=""

    if [ -n "${!var}" ]; then
        return
    fi

    if [ "$ASSUME_YES" = 1 ] || [ ! -t 0 ]; then
        # Name the flag the way the user would type it, not the way the
        # variable is spelled.
        local flag="--${var,,}"
        flag="${flag//_/-}"
        [ -n "$default" ] || die "$flag is required when running non-interactively"
        printf -v "$var" '%s' "$default"
        return
    fi

    if [ "$secret" = 1 ]; then
        read -r -s -p "    $question: " answer; echo
    elif [ -n "$default" ]; then
        read -r -p "    $question [$default]: " answer
        answer="${answer:-$default}"
    else
        read -r -p "    $question: " answer
    fi

    printf -v "$var" '%s' "$answer"
}

# --------------------------------------------------------------------------
# Preflight
# --------------------------------------------------------------------------

step "Checking the environment"

[ "$(id -u)" = 0 ] || die "Run this as root (sudo ./install.sh)"

if [ ! -f "$SRC/lib/utils.php" ] || [ ! -d "$SRC/www" ]; then
    die "Run this from a hostdeployer checkout; $SRC does not look like one"
fi

if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    if [ "${ID:-}" != "debian" ]; then
        warn "This targets Debian; found '${ID:-unknown}'. Package names may differ."
    elif [ "${VERSION_ID:-}" != "13" ]; then
        warn "This targets Debian 13 (trixie); found ${VERSION_ID:-unknown}."
        warn "PHP $PHP_VERSION is in trixie's main archive but not in earlier releases."
    else
        info "Debian ${VERSION_ID} (${VERSION_CODENAME:-trixie})"
    fi
fi

if [ "$(systemctl is-system-running 2>/dev/null || true)" = "offline" ]; then
    die "systemd is not running; this script manages services"
fi

# --------------------------------------------------------------------------
# Gather what we need
# --------------------------------------------------------------------------

step "Deployment settings"

# iproute2 makes this nicer but is not required: it is only used to suggest
# defaults, and this runs before apt has had a chance to install anything.
if [ -z "$INTERFACE" ] && [ "$ASSUME_YES" != 1 ]; then
    info "Interfaces on this machine:"
    if command -v ip >/dev/null 2>&1; then
        ip -o -4 addr show scope global 2>/dev/null \
            | awk '{printf "      %-12s %s\n", $2, $4}' || true
    else
        for iface in /sys/class/net/*; do
            [ "$(basename "$iface")" = lo ] || printf '      %s\n' "$(basename "$iface")"
        done
    fi
fi

DEFAULT_IFACE=""
if command -v ip >/dev/null 2>&1; then
    DEFAULT_IFACE="$(ip -o -4 route show default 2>/dev/null | awk '{print $5; exit}')"
fi
prompt INTERFACE "Interface serving the provisioning network" "$DEFAULT_IFACE"
[ -n "$INTERFACE" ] || die "An interface is required"
[ -d "/sys/class/net/$INTERFACE" ] || die "No such interface: $INTERFACE"

DEFAULT_IP=""
if command -v ip >/dev/null 2>&1; then
    DEFAULT_IP="$(ip -o -4 addr show dev "$INTERFACE" scope global 2>/dev/null | awk '{sub(/\/.*/,"",$4); print $4; exit}')"
fi
prompt SERVER_IP "This server's address on that network" "$DEFAULT_IP"
valid_ip "$SERVER_IP" || die "Invalid server address: $SERVER_IP"

prompt DHCP_START "First address in the DHCP pool"
prompt DHCP_END   "Last address in the DHCP pool"
prompt NETMASK    "Netmask" "$NETMASK"
prompt GATEWAY    "Gateway offered to booting servers"
prompt DNS        "DNS servers (comma separated)"
prompt NTP        "NTP servers (comma separated)" "$DNS"
prompt DOMAIN     "Search domain" "$DOMAIN"

valid_ip "$DHCP_START" || die "Invalid pool start: $DHCP_START"
valid_ip "$DHCP_END"   || die "Invalid pool end: $DHCP_END"
valid_ip "$GATEWAY"    || die "Invalid gateway: $GATEWAY"
valid_ip "$NETMASK"    || die "Invalid netmask: $NETMASK"
valid_ip_list "$DNS"   || die "Invalid DNS server list: $DNS"
valid_ip_list "$NTP"   || die "Invalid NTP server list: $NTP"

# A mask has to be a contiguous run of ones; anything else computes a
# nonsensical network and costs the client its default route.
MASK_INT="$(ip_to_int "$NETMASK")"
INVERTED=$(( (~MASK_INT) & 0xFFFFFFFF ))
(( ((INVERTED + 1) & INVERTED) == 0 )) || die "Netmask is not contiguous: $NETMASK"

(( $(ip_to_int "$DHCP_START") <= $(ip_to_int "$DHCP_END") )) \
    || die "The DHCP pool starts above where it ends"

NETWORK_INT=$(( $(ip_to_int "$DHCP_START") & MASK_INT ))
for pair in "pool end:$DHCP_END" "gateway:$GATEWAY" "server:$SERVER_IP"; do
    (( ($(ip_to_int "${pair#*:}") & MASK_INT) == NETWORK_INT )) \
        || die "${pair%%:*} ${pair#*:} is outside the provisioning subnet"
done

# The server must not be inside the pool it hands out.
if (( $(ip_to_int "$SERVER_IP") >= $(ip_to_int "$DHCP_START") \
   && $(ip_to_int "$SERVER_IP") <= $(ip_to_int "$DHCP_END") )); then
    die "This server's address ($SERVER_IP) is inside the DHCP pool; it would be handed out"
fi

step "Credentials"

if [ -z "$ADMIN_PASSWORD" ] && [ "$ASSUME_YES" = 1 ]; then
    ADMIN_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 20)"
    note "Dashboard password for 'admin': $ADMIN_PASSWORD"
    info "Generated a dashboard password; it is printed at the end."
else
    prompt ADMIN_PASSWORD "Dashboard password for 'admin'" "" 1
fi
[ -n "$ADMIN_PASSWORD" ] || die "An admin password is required"

prompt ILO_USER     "iLO username" "$ILO_USER"
prompt ILO_PASSWORD "iLO password" "" 1
prompt ESXI_PASSWORD "ESXi root password for new hosts" "" 1

[ -n "$ESXI_PASSWORD" ] || die "An ESXi root password is required; it is what hosts are installed with"

# --------------------------------------------------------------------------
# Packages
# --------------------------------------------------------------------------

PACKAGES=(
    nginx
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli"
    "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-curl"
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml"
    kea-dhcp4-server kea-ctrl-agent
    libarchive-tools          # bsdtar, for extracting ESXi ISOs
    python3-requests python3-venv
    openssl rsync sqlite3 ca-certificates iproute2
    ipxe                      # the chainloader for the PXE branch
    tftpd-hpa                 # only the UEFI-PXE branch needs it
)

if [ "$SKIP_PACKAGES" = 1 ]; then
    step "Packages"
    skip "--skip-packages"
else
    step "Installing packages"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends "${PACKAGES[@]}" >/dev/null
    info "${#PACKAGES[@]} packages present"
fi

# Sodium is what encrypts the stored ESXi passwords. Debian builds it into
# phpX.Y-common rather than shipping a separate package, so this is a check
# and not an install -- and a failure here has to be loud, because without it
# the appliance stores credentials it cannot read back.
if ! "php${PHP_VERSION}" -m 2>/dev/null | grep -qx sodium; then
    die "PHP $PHP_VERSION has no sodium extension. It encrypts the stored ESXi
     passwords and there is no fallback. Install php${PHP_VERSION}-common, or
     whichever package provides sodium.so on this system."
fi
for ext in pdo_sqlite curl mbstring; do
    "php${PHP_VERSION}" -m 2>/dev/null | grep -qx "$ext" || die "PHP $PHP_VERSION is missing the $ext extension"
done
info "PHP $("php${PHP_VERSION}" -r 'echo PHP_VERSION;') with sodium, pdo_sqlite, curl, mbstring"

# --------------------------------------------------------------------------
# The tree
# --------------------------------------------------------------------------

step "Installing to $ROOT"

install -d -m 0750 -o root -g www-data "$ROOT"

# vendor/ and tests/ are development-only; .git has no business on an
# appliance. config/ is excluded so a re-run never overwrites live secrets.
rsync -a --delete \
    --exclude '.git' \
    --exclude '.github' \
    --exclude 'vendor' \
    --exclude 'tests' \
    --exclude 'logs' \
    --exclude 'config' \
    --exclude 'esxi/*/' \
    "$SRC"/ "$ROOT"/

install -d -m 0750 -o root    -g www-data "$ROOT/config"
install -d -m 0750 -o www-data -g www-data "$ROOT/logs"
install -d -m 0755 -o root    -g www-data "$ROOT/esxi"
install -d -m 0750 -o www-data -g www-data "$ROOT/templates/backups"
install -d -m 0755 -o root    -g www-data "$ROOT/ipxe"

# The templates directory is edited through the admin UI.
chown -R root:www-data "$ROOT/templates"
chmod 0770 "$ROOT/templates"
find "$ROOT/templates" -type f -exec chmod 0660 {} +

info "tree installed, logs and templates writable by www-data"

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

step "Configuration"

DNS_JSON="$(printf '%s' "$DNS" | tr -d ' ' | awk -F, '{for(i=1;i<=NF;i++) printf "%s\"%s\"", (i>1?", ":""), $i}')"
NTP_JSON="$(printf '%s' "$NTP" | tr -d ' ' | awk -F, '{for(i=1;i<=NF;i++) printf "%s\"%s\"", (i>1?", ":""), $i}')"

if [ -f "$ROOT/config/global_config.json" ]; then
    skip "config/global_config.json exists"
else
    cat > "$ROOT/config/global_config.json" <<JSON
{
    "deployment": {
        "esxi_versions": {},
        "kickstart_templates": {
            "standard": "$ROOT/templates/kickstart_template_std.cfg",
            "vcf": "$ROOT/templates/kickstart_template_vcf.cfg"
        },
        "default_version": "",
        "default_deployment_type": "standard",
        "auto_registration": {
            "enabled": true,
            "default_status": "pending",
            "max_wait_time": 7200,
            "retry_interval": 60,
            "notification_email": ""
        },
        "waiting_template_path": "$ROOT/templates/waiting_template.cfg"
    },
    "network": {
        "dhcp_range_start": "$DHCP_START",
        "dhcp_range_end": "$DHCP_END",
        "subnet_mask": "$NETMASK",
        "gateway": "$GATEWAY",
        "domain": "$DOMAIN",
        "dns_servers": [$DNS_JSON],
        "ntp_servers": [$NTP_JSON]
    },
    "ilo": {
        "admin_user": "$ILO_USER",
        "scan_range_start": "",
        "scan_range_end": ""
    },
    "webserver": {
        "ip": "$SERVER_IP",
        "port": 80,
        "url": "http://$SERVER_IP"
    },
    "security": {
        "secure_boot_enabled": false
    },
    "paths": {
        "logs_dir": "$ROOT/logs",
        "templates_dir": "$ROOT/templates"
    }
}
JSON
    info "wrote config/global_config.json"
fi

if [ -f "$ROOT/config/credentials.json" ]; then
    skip "config/credentials.json exists (secrets left alone)"
else
    # Written in plaintext and encrypted below, so the passwords never have to
    # be passed through an extra process to get in.
    # shellcheck disable=SC2016  # PHP variables, passed as $argv below
    "php${PHP_VERSION}" -r '
        $out = ["ilo" => ["admin_user" => $argv[1], "admin_password" => $argv[2], "hosts" => (object)[]],
                "esxi" => ["root_password" => $argv[3], "hosts" => (object)[]]];
        file_put_contents($argv[4], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$ILO_USER" "$ILO_PASSWORD" "$ESXI_PASSWORD" "$ROOT/config/credentials.json"
    info "wrote config/credentials.json"
fi

if [ -f "$ROOT/config/auth_config.php" ]; then
    skip "config/auth_config.php exists (accounts and tokens left alone)"
else
    HASH="$("php${PHP_VERSION}" "$ROOT/lib/auth.php" "$ADMIN_PASSWORD" | tail -n1)"
    [ -n "$HASH" ] || die "Could not generate the admin password hash"

    cat > "$ROOT/config/auth_config.php" <<PHPEOF
<?php
/**
 * Written by install.sh. Add accounts and API tokens here.
 *
 *   php $ROOT/lib/auth.php 'password'          -> a user password hash
 *   php $ROOT/lib/api_auth.php name role       -> an API token
 */

return [
    'users' => [
        'admin' => [
            'password_hash' => '$HASH',
            'role'          => 'admin',
            'name'          => 'Administrator',
        ],
    ],
    'roles' => [
        'admin' => [
            'description' => 'Full administrative access',
            'permissions' => ['read', 'write', 'approve', 'scan', 'settings'],
        ],
        'operator' => [
            'description' => 'Deployment operations access',
            'permissions' => ['read', 'approve', 'scan'],
        ],
    ],
    'api_tokens' => [
    ],
];
PHPEOF
    info "wrote config/auth_config.php with the admin account"
fi

chown root:www-data "$ROOT"/config/*.json "$ROOT/config/auth_config.php"
chmod 0640 "$ROOT"/config/*.json "$ROOT/config/auth_config.php"

# --------------------------------------------------------------------------
# Secrets
# --------------------------------------------------------------------------

step "Secrets"

if [ -f "$ROOT/config/secret.key" ]; then
    skip "config/secret.key exists -- never regenerate it, every stored password depends on it"
else
    ( cd "$ROOT" && "php${PHP_VERSION}" lib/secrets.php --encrypt-credentials >/dev/null )
    chown root:www-data "$ROOT/config/secret.key"
    chmod 0640 "$ROOT/config/secret.key"
    info "generated config/secret.key and encrypted the stored credentials"
    note "Back up $ROOT/config/secret.key. Without it every stored password is unreadable."
fi

# The admin UI shells out to the Python helpers, which reach their data over
# the API and so need a credential to do it with.
if [ -f "$ROOT/config/api_local_token" ] && grep -q "local-helpers" "$ROOT/config/auth_config.php"; then
    skip "local API token already installed"
else
    TOKEN_OUT="$( cd "$ROOT" && "php${PHP_VERSION}" lib/api_auth.php --local )"
    TOKEN_HASH="$(printf '%s\n' "$TOKEN_OUT" | sed -n "s/.*'token_hash' => '\([0-9a-f]*\)'.*/\1/p")"
    [ -n "$TOKEN_HASH" ] || die "Could not generate the local API token"

    # shellcheck disable=SC2016  # PHP variables, passed as $argv below
    "php${PHP_VERSION}" -r '
        $file = $argv[1];
        $s = file_get_contents($file);
        if (str_contains($s, "local-helpers")) { exit(0); }
        $entry = "    \x27api_tokens\x27 => [\n"
               . "        // Used by the admin UI when it runs the Python helpers.\n"
               . "        \x27local-helpers\x27 => [\n"
               . "            \x27token_hash\x27 => \x27" . $argv[2] . "\x27,\n"
               . "            \x27role\x27       => \x27admin\x27,\n"
               . "        ],\n";
        $out = str_replace("    \x27api_tokens\x27 => [\n", $entry, $s, $count);
        if ($count !== 1) { fwrite(STDERR, "could not place the token\n"); exit(1); }
        file_put_contents($file, $out);
    ' "$ROOT/config/auth_config.php" "$TOKEN_HASH" || die "Could not register the local API token"

    chown root:www-data "$ROOT/config/api_local_token"
    chmod 0640 "$ROOT/config/api_local_token"
    info "installed the local API token"
fi

# --------------------------------------------------------------------------
# Python helpers
# --------------------------------------------------------------------------

step "Python helpers"

if ! python3 -c 'import redfish' 2>/dev/null; then
    if apt-cache show python3-redfish >/dev/null 2>&1 && [ "$SKIP_PACKAGES" != 1 ]; then
        apt-get install -y -qq --no-install-recommends python3-redfish >/dev/null
        info "installed python3-redfish from apt"
    else
        # Debian 13 marks the system Python externally managed (PEP 668), so a
        # venv is the supported way in. --system-site-packages keeps
        # python3-requests from apt visible rather than installing it twice.
        if [ ! -x "$ROOT/venv/bin/python3" ]; then
            python3 -m venv --system-site-packages "$ROOT/venv"
        fi
        if "$ROOT/venv/bin/pip" install --quiet redfish 2>/dev/null; then
            info "installed redfish into $ROOT/venv"
        else
            warn "could not install the redfish module"
            note "Secure boot management needs the Python 'redfish' module:
       $ROOT/venv/bin/pip install redfish
     Everything else works without it."
        fi
    fi
fi

# The pool below puts the venv first on PATH so "python3" resolves to it when
# PHP shells out. Harmless when the venv does not exist.
chown -R root:www-data "$ROOT/venv" 2>/dev/null || true

# --------------------------------------------------------------------------
# PHP-FPM
# --------------------------------------------------------------------------

step "PHP-FPM"

PHP_INI_DIR="/etc/php/${PHP_VERSION}/fpm/conf.d"
[ -d "$PHP_INI_DIR" ] || die "No PHP-FPM configuration directory at $PHP_INI_DIR"

# A drop-in rather than edits to php.ini, so a package upgrade does not revert
# it and so what this appliance changed is visible in one file.
cat > "$PHP_INI_DIR/99-hostdeployer.ini" <<'INI'
; hostdeployer. Managed by install.sh.

; ESXi ISOs are several gigabytes and are uploaded through the admin UI.
; nginx has a matching client_max_body_size on the TLS listener.
upload_max_filesize = 16G
post_max_size = 16G

; Hashing and extracting an image of that size outlasts the defaults.
max_execution_time = 1800
max_input_time = 1800

; Enough to render the dashboard and stream an upload, which is done in
; chunks; this is not a limit the ISO has to fit inside.
memory_limit = 512M

; The boot endpoints answer firmware, which reports nothing useful about a
; PHP error. Errors belong in the log.
display_errors = Off
log_errors = On

expose_php = Off
INI

POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
if [ -f "$POOL" ]; then
    # DHCP_BACKEND makes the admin UI write Kea configuration rather than ISC.
    # PATH puts the venv first so the Python helpers find the redfish module.
    if grep -q '^env\[DHCP_BACKEND\]' "$POOL"; then
        skip "pool environment already set"
    else
        cat >> "$POOL" <<POOLEOF

; ---- hostdeployer (install.sh) ----
; The admin UI generates Kea configuration, not ISC dhcpd.
env[DHCP_BACKEND] = kea
; The venv first, so python3 finds the redfish module when PHP shells out.
env[PATH] = $ROOT/venv/bin:/usr/local/bin:/usr/bin:/bin
env[AUTODEPLOY_ROOT] = $ROOT
POOLEOF
        info "set DHCP_BACKEND=kea and the helper PATH in the FPM pool"
    fi
fi

FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true
# Restarted again at the end, after group membership changes: a running worker
# keeps the groups it started with.
systemctl restart "php${PHP_VERSION}-fpm"
info "php${PHP_VERSION}-fpm running on $FPM_SOCKET"

# --------------------------------------------------------------------------
# TLS
# --------------------------------------------------------------------------

step "TLS certificate"

install -d -m 0755 "$CERT_DIR"

if [ -f "$CERT_DIR/server.crt" ] && [ -f "$CERT_DIR/server.key" ]; then
    skip "certificate exists at $CERT_DIR"
else
    HOSTNAME_FQDN="$(hostname -f 2>/dev/null || hostname)"
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout "$CERT_DIR/server.key" -out "$CERT_DIR/server.crt" \
        -subj "/CN=$HOSTNAME_FQDN" \
        -addext "subjectAltName=DNS:$HOSTNAME_FQDN,DNS:localhost,IP:$SERVER_IP,IP:127.0.0.1" \
        2>/dev/null
    chmod 0640 "$CERT_DIR/server.key"
    chgrp www-data "$CERT_DIR/server.key"
    chmod 0644 "$CERT_DIR/server.crt"
    info "self-signed certificate for $HOSTNAME_FQDN and $SERVER_IP, valid 10 years"
    note "The dashboard uses a self-signed certificate. Replace
       $CERT_DIR/server.{crt,key} with a real one, or trust it once."
fi

# --------------------------------------------------------------------------
# nginx
# --------------------------------------------------------------------------

step "nginx"

# The site config ships with a placeholder socket path; Debian's is versioned.
sed "s#server unix:/var/run/php/php-fpm.sock;#server unix:${FPM_SOCKET};#" \
    "$ROOT/nginx.conf" > /etc/nginx/sites-available/autodeploy

grep -q "unix:${FPM_SOCKET}" /etc/nginx/sites-available/autodeploy \
    || die "Could not point the site at $FPM_SOCKET; check the upstream block in nginx.conf"

ln -sf /etc/nginx/sites-available/autodeploy /etc/nginx/sites-enabled/autodeploy

# Debian's default site owns "default_server" on port 80, which the boot chain
# needs: a firmware asking for /mboot.efi has no Host header worth matching.
if [ -e /etc/nginx/sites-enabled/default ]; then
    rm -f /etc/nginx/sites-enabled/default
    info "removed the default site (it owns default_server on :80)"
fi

nginx -t >/dev/null 2>&1 || { nginx -t; die "The nginx configuration was rejected"; }
systemctl enable --now nginx >/dev/null 2>&1 || true
systemctl reload nginx
info "site enabled, configuration valid"

# --------------------------------------------------------------------------
# iPXE and TFTP
# --------------------------------------------------------------------------

step "iPXE chainloader"

IPXE_SRC=""
for candidate in /usr/lib/ipxe/ipxe.efi /usr/lib/ipxe/ipxe-x86_64.efi /usr/share/ipxe/ipxe.efi; do
    [ -f "$candidate" ] && { IPXE_SRC="$candidate"; break; }
done

if [ -n "$IPXE_SRC" ]; then
    install -m 0644 -o root -g www-data "$IPXE_SRC" "$ROOT/ipxe/ipxe.efi"
    info "installed ipxe.efi from $IPXE_SRC"
elif [ -f "$ROOT/ipxe/ipxe.efi" ]; then
    skip "ipxe.efi already present"
else
    warn "no ipxe.efi found"
    note "The PXE branch needs a UEFI iPXE binary at $ROOT/ipxe/ipxe.efi.
     Debian's 'ipxe' package did not provide one here; build it, or drop in
     ipxe.efi from https://boot.ipxe.org/ipxe.efi.
     Hosts that support UEFI HTTP Boot do not need it -- they go straight to
     the ESXi loader."
fi

# The UEFI-PXE class is the only branch that needs TFTP: firmware PXE has no
# HTTP client. Everything else is served over HTTP.
if [ -f "$ROOT/ipxe/ipxe.efi" ]; then
    install -d -m 0755 /srv/tftp
    install -m 0644 "$ROOT/ipxe/ipxe.efi" /srv/tftp/ipxe.efi

    if [ -f /etc/default/tftpd-hpa ]; then
        sed -i 's#^TFTP_DIRECTORY=.*#TFTP_DIRECTORY="/srv/tftp"#' /etc/default/tftpd-hpa
        systemctl enable --now tftpd-hpa >/dev/null 2>&1 || true
        systemctl restart tftpd-hpa || warn "tftpd-hpa did not start"
        info "TFTP serving /srv/tftp for the UEFI-PXE branch"
    fi
fi

# --------------------------------------------------------------------------
# Kea control socket access
# --------------------------------------------------------------------------

step "Kea control socket"

# The admin UI changes DHCP through Kea's control API rather than by rewriting
# /etc/kea and restarting the daemon. That removes the sudo rule this used to
# need -- the web server no longer runs anything as root -- and the restart,
# which dropped every DHCP exchange in flight at that moment.
#
# The socket is the trust boundary now: anything that can write to it can
# reconfigure DHCP for the whole provisioning network. It is granted to the web
# server's group and nothing wider.
KEA_USER="$(systemctl show -p User --value kea-dhcp4-server 2>/dev/null || true)"
KEA_USER="${KEA_USER:-_kea}"
KEA_GROUP="$(id -gn "$KEA_USER" 2>/dev/null || echo "$KEA_USER")"

if id -nG www-data 2>/dev/null | tr ' ' '\n' | grep -qx "$KEA_GROUP"; then
    skip "www-data is already in the $KEA_GROUP group"
else
    if getent group "$KEA_GROUP" >/dev/null 2>&1; then
        usermod -aG "$KEA_GROUP" www-data
        info "added www-data to the $KEA_GROUP group"
    else
        warn "no '$KEA_GROUP' group; cannot grant socket access"
        note "The admin UI needs write access to Kea's control socket to change
     DHCP settings. Grant it however this system expects, or DHCP has to be
     changed by editing /etc/kea/kea-dhcp4.conf and restarting."
    fi
fi

# Kea creates the socket under a runtime directory whose mode systemd owns.
# Without the group bit, being in the group is not enough to traverse it.
install -d -m 0750 -o "$KEA_USER" -g "$KEA_GROUP" /run/kea 2>/dev/null || true
mkdir -p /etc/systemd/system/kea-dhcp4-server.service.d
cat > /etc/systemd/system/kea-dhcp4-server.service.d/10-socket-access.conf <<UNIT
# Let the admin UI reach the control socket. hostdeployer changes DHCP through
# Kea's API, which needs write access to the socket and traverse on its
# directory -- and nothing else. Installed by install.sh.
[Service]
RuntimeDirectory=kea
RuntimeDirectoryMode=0750
UMask=0007
UNIT
systemctl daemon-reload

# The old sudo rule is not needed any more; leaving it behind would leave a
# www-data to root path in place for a script nothing calls.
if [ -f /etc/sudoers.d/autodeploy ]; then
    rm -f /etc/sudoers.d/autodeploy
    info "removed the old sudo rule (the API replaced it)"
fi

# --------------------------------------------------------------------------
# Kea
# --------------------------------------------------------------------------

step "Kea DHCPv4"

install -d -m 0755 /var/log/kea /var/lib/kea

if [ -f /etc/kea/kea-dhcp4.conf ] && grep -q "hostdeployer" /etc/kea/kea-dhcp4.conf; then
    skip "/etc/kea/kea-dhcp4.conf already written by this installer"
else
    [ -f /etc/kea/kea-dhcp4.conf ] && cp -p /etc/kea/kea-dhcp4.conf "/etc/kea/kea-dhcp4.conf.bak.$(date +%Y%m%d%H%M%S)"

    PREFIX_LEN=0
    tmp="$MASK_INT"
    while [ "$tmp" -ne 0 ]; do
        PREFIX_LEN=$(( PREFIX_LEN + (tmp & 1) ))
        tmp=$(( (tmp >> 1) & 0x7FFFFFFF ))
    done
    SUBNET="$(printf '%d.%d.%d.%d' \
        $(( (NETWORK_INT >> 24) & 255 )) $(( (NETWORK_INT >> 16) & 255 )) \
        $(( (NETWORK_INT >> 8) & 255 ))  $(( NETWORK_INT & 255 )))"

    "$ROOT/deploy/kea-config.sh" \
        --interface "$INTERFACE" \
        --server-ip "$SERVER_IP" \
        --subnet "$SUBNET/$PREFIX_LEN" \
        --pool "$DHCP_START - $DHCP_END" \
        --gateway "$GATEWAY" \
        --dns "$DNS" \
        --domain "$DOMAIN" \
        > /etc/kea/kea-dhcp4.conf

    chmod 0644 /etc/kea/kea-dhcp4.conf
    info "wrote /etc/kea/kea-dhcp4.conf for $SUBNET/$PREFIX_LEN on $INTERFACE"
fi

kea-dhcp4 -t /etc/kea/kea-dhcp4.conf >/dev/null 2>&1 \
    || { kea-dhcp4 -t /etc/kea/kea-dhcp4.conf; die "Kea rejected the configuration"; }

systemctl enable --now kea-dhcp4-server >/dev/null 2>&1 || true
systemctl restart kea-dhcp4-server
info "kea-dhcp4-server running on $INTERFACE"

# A running worker keeps the supplementary groups it started with, so the
# group added above only takes effect after this.
systemctl restart "php${PHP_VERSION}-fpm"

if systemctl list-unit-files kea-ctrl-agent.service >/dev/null 2>&1; then
    systemctl enable --now kea-ctrl-agent >/dev/null 2>&1 || true
fi

# --------------------------------------------------------------------------
# Verify
# --------------------------------------------------------------------------

step "Verifying"

FAILED=0
check() {
    local label="$1"; shift
    if "$@" >/dev/null 2>&1; then
        printf '    %s[ ok ]%s %s\n' "$C_OK" "$C_OFF" "$label"
    else
        printf '    %s[fail]%s %s\n' "$C_ERR" "$C_OFF" "$label"
        FAILED=1
    fi
}

check "php${PHP_VERSION}-fpm running"   systemctl is-active --quiet "php${PHP_VERSION}-fpm"
check "nginx running"                   systemctl is-active --quiet nginx
check "kea-dhcp4-server running"        systemctl is-active --quiet kea-dhcp4-server
check "the inventory database opens"    sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/store.php'; storeLoadHosts();"
check "the stored ESXi password decrypts" sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/store.php'; exit(storeLoadCredentials('esxi')['root_password'] === '' ? 1 : 0);"
check "the dashboard answers on 443"    curl -skf -o /dev/null "https://127.0.0.1/admin/login.php"
check "the boot chain answers on 80"    bash -c "curl -sf -o /dev/null -w '%{http_code}' 'http://127.0.0.1/boot.ipxe.php?mac=00:00:00:00:00:01' | grep -q 200"
check "the API rejects an unauthenticated call" bash -c "curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1/api/v1/hosts | grep -q 401"
check "the admin UI can reach Kea's control socket" sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/kea.php'; exit(keaStatus()['available'] ? 0 : 1);"

chown -R www-data:www-data "$ROOT/logs"

# --------------------------------------------------------------------------
# Done
# --------------------------------------------------------------------------

if [ "$FAILED" = 1 ]; then
    printf '\n%s==>%s Some checks failed. See %s/logs/ and journalctl -u nginx -u php%s-fpm -u kea-dhcp4-server\n' \
        "$C_ERR" "$C_OFF" "$ROOT" "$PHP_VERSION"
else
    printf '\n%s==>%s hostdeployer is installed and running.\n' "$C_OK" "$C_OFF"
fi

cat <<SUMMARY

    Dashboard   https://$SERVER_IP/admin/   (user: admin)
    API         https://$SERVER_IP/api/v1/hosts
    Boot chain  http://$SERVER_IP/          DHCP pool $DHCP_START - $DHCP_END on $INTERFACE

    Next:
      1. Upload an ESXi ISO under Settings > ESXi Versions. Nothing can be
         deployed until one is installed.
      2. Set the iLO scan range under Settings if you want hardware discovery.
         DHCP settings are changed under Settings > Network and applied to the
         running Kea server through its API -- no restart, no root.
      3. Boot a server on $INTERFACE. It registers as pending and waits for
         you to approve it.

SUMMARY

if [ ${#NOTES[@]} -gt 0 ]; then
    printf '%s    Worth reading:%s\n\n' "$C_WARN" "$C_OFF"
    for n in "${NOTES[@]}"; do
        printf '     * %s\n' "$n"
    done
    echo
fi

exit "$FAILED"
