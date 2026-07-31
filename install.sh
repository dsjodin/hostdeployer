#!/bin/bash
# Install hostdeployer on Debian 13 (trixie).
#
# Everything the README describes as a manual step, done once and idempotently:
# packages, the tree under /srv/autodeploy, PHP-FPM, nginx with a certificate,
# Kea DHCPv4, the secrets and the API token.
#
# Kea only. ISC dhcpd is end-of-life since December 2022, is not in Debian 13,
# and has no control API -- which is what lets the admin UI change DHCP without
# running anything as root. Supporting both would mean two config generators
# and keeping the sudo rule alive for the dead one.
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

step()  { CURRENT_STEP="$*"; printf '\n%s==>%s %s\n' "$C_OK" "$C_OFF" "$*"; }
info()  { printf '    %s\n' "$*"; }
skip()  { printf '    %sskipped: %s%s\n' "$C_DIM" "$*" "$C_OFF"; }
warn()  { printf '    %swarning: %s%s\n' "$C_WARN" "$*" "$C_OFF"; }
die()   { printf '\n%serror:%s %s\n' "$C_ERR" "$C_OFF" "$*" >&2; exit 1; }

# Collected and printed at the end, so a thing needing attention is not lost
# in several hundred lines of apt output.
NOTES=()
note() { NOTES+=("$*"); }

# Under set -e any unguarded command ends the run, and every step after it --
# nginx, Kea -- then never happens. Without this the only evidence is that the
# output stops, which looks exactly like a step that finished quietly. Name the
# step, the line and the command instead.
#
# die() exits explicitly and so does not fire ERR; it prints its own message.
CURRENT_STEP="startup"
# shellcheck disable=SC2317  # reached through the ERR trap below, not by a call
on_error() {
    local rc=$? line="$1" command="$2"
    printf '\n%serror:%s aborted during "%s" at line %s: %s (exit %s)\n' \
        "$C_ERR" "$C_OFF" "$CURRENT_STEP" "$line" "$command" "$rc" >&2
    printf '    Nothing after that step ran. Fix the cause and re-run: this\n' >&2
    printf '    script is idempotent and leaves existing secrets alone.\n' >&2
}
trap 'on_error "$LINENO" "$BASH_COMMAND"' ERR

# --------------------------------------------------------------------------
# Arguments
# --------------------------------------------------------------------------

SERVER_IP=""
ADMIN_IP=""
ADMIN_ALLOW=""
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
  --server-ip ADDR        This server's address on the deploy network
  --admin-ip ADDR         Address the dashboard and API bind to. Defaults to
                          --server-ip, which is a single-interface install.
                          Give a different address to keep the admin interface
                          off the deploy network entirely.
  --admin-allow CIDR      Network allowed to reach the dashboard and API
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
        --admin-ip)        ADMIN_IP="${2:-}"; shift 2 ;;
        --admin-allow)     ADMIN_ALLOW="${2:-}"; shift 2 ;;
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

# Defaults to the deploy address, which is the single-interface installation
# and what every existing install has. Given a different address, the dashboard
# and the API bind there and nowhere else -- the deploy network then carries the
# boot chain and nothing an operator could log in to.
ADMIN_IP="${ADMIN_IP:-$SERVER_IP}"
valid_ip "$ADMIN_IP" || die "Invalid admin address: $ADMIN_IP"

if [ "$ADMIN_IP" != "$SERVER_IP" ] && [ ! -d /sys/class/net ]; then
    :
elif [ "$ADMIN_IP" != "$SERVER_IP" ] \
     && ! ip -o -4 addr show scope global 2>/dev/null | grep -q " ${ADMIN_IP}/"; then
    die "No interface on this machine holds $ADMIN_IP.
     nginx binds that address explicitly and will not start without it."
fi

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
# Only what the code actually loads. mbstring and php-xml used to be required
# here and installed above, and nothing in lib/, www/ or scripts/ calls a single
# mb_* function, DOMDocument or SimpleXML -- so the check stopped installations
# over an extension the appliance never loads. Every string path goes through
# preg_*, str_* and htmlspecialchars(..., 'UTF-8'), which live in Core and pcre.
#
# Each message names the package, because a check that refuses to continue and
# does not say what to install is a check that costs more than it saves.
declare -A REQUIRED_EXTENSIONS=(
    [pdo_sqlite]="php${PHP_VERSION}-sqlite3"   # lib/db.php: the whole inventory
    [curl]="php${PHP_VERSION}-curl"            # outbound calls from the admin UI
)
for ext in "${!REQUIRED_EXTENSIONS[@]}"; do
    "php${PHP_VERSION}" -m 2>/dev/null | grep -qx "$ext" || die "PHP $PHP_VERSION is missing the $ext extension.
     Install it with: apt install ${REQUIRED_EXTENSIONS[$ext]}
     (or, if the package is installed already: phpenmod -v $PHP_VERSION $ext)"
done
info "PHP $("php${PHP_VERSION}" -r 'echo PHP_VERSION;') with sodium, pdo_sqlite and curl"

# --------------------------------------------------------------------------
# The tree
# --------------------------------------------------------------------------

step "Installing to $ROOT"

install -d -m 0750 -o root -g www-data "$ROOT"

# vendor/ and tests/ are development-only; .git has no business on an
# appliance. config/ is excluded so a re-run never overwrites live secrets.
# --delete removes anything under $ROOT that is not in the checkout, which is
# what keeps an upgrade from leaving a deleted file behind. Everything the
# appliance creates after installation therefore has to be excluded, or a
# re-run destroys it:
#
#   venv/      built by the Python step further down. Not excluded, rsync tried
#              to delete it on every re-run, printed a page of "cannot delete
#              non-empty directory", and left a half-removed virtualenv behind.
#   templates/ the kickstart templates are edited and uploaded through the
#              admin UI. Copying the shipped ones over them reverted an
#              operator's edits, and --delete removed every template they had
#              uploaded along with the backups the UI made before each save.
#              Seeded below instead, without overwriting.
rsync -a --delete \
    --exclude '.git' \
    --exclude '.github' \
    --exclude 'vendor' \
    --exclude 'tests' \
    --exclude 'logs' \
    --exclude 'config' \
    --exclude 'venv' \
    --exclude 'templates' \
    --exclude 'esxi/*/' \
    "$SRC"/ "$ROOT"/

# 3770, not 0750. The inventory is a SQLite database in here, and SQLite has to
# create the database plus its -wal and -shm sidecars -- which needs write on
# the directory, not just on the file. saveJsonConfig() needs it too: it writes
# a temporary file beside the target and renames over it, which is what makes
# the write atomic. With 0750 the web server could read config/ and nothing
# else, so the dashboard came up and every host lookup failed with
# "unable to open database file".
#
# The sticky bit is what keeps that from being a downgrade. A process that can
# write to a directory can normally replace any file in it whatever that file's
# own mode says, so 0770 alone would let www-data swap out auth_config.php --
# which is PHP that gets require()d -- or secret.key. With +t only the owner may
# unlink or rename, so the three root-owned files below stay out of reach while
# the ones the application maintains do not.
install -d -m 3770 -o root    -g www-data "$ROOT/config"
install -d -m 0750 -o www-data -g www-data "$ROOT/logs"
install -d -m 0755 -o root    -g www-data "$ROOT/esxi"
install -d -m 2770 -o root    -g www-data "$ROOT/templates"
install -d -m 2770 -o root    -g www-data "$ROOT/templates/backups"
install -d -m 0755 -o root    -g www-data "$ROOT/ipxe"

# Seeded, not synchronised. The admin UI edits these files, uploads new ones
# and keeps a backup before every save, so the shipped copies are a starting
# point rather than the truth: an existing file is left exactly as it is, and
# what an operator added is never touched.
#
# setgid on the directories above is what keeps that working -- a template
# uploaded by the web server lands in the www-data group, which is what the
# 0660 below assumes.
seeded=0
kept=0
for template in "$SRC"/templates/*.cfg; do
    [ -f "$template" ] || continue

    target="$ROOT/templates/$(basename "$template")"
    if [ -e "$target" ]; then
        kept=$((kept + 1))
    else
        install -m 0660 -o root -g www-data "$template" "$target"
        seeded=$((seeded + 1))
    fi
done

# Modes only, no ownership: a template the web server uploaded is owned by
# www-data, and taking that away would stop it being editable through the UI
# that created it.
chmod 2770 "$ROOT/templates" "$ROOT/templates/backups"
find "$ROOT/templates" -type f -exec chmod 0660 {} +

info "tree installed; templates: $seeded seeded, $kept kept as they are"

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
            'permissions' => ['read', 'write', 'approve', 'scan', 'settings', 'templates'],
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

# Ownership splits by who writes the file, because the sticky bit on config/
# keys off exactly that: only a file's owner may replace it.
#
# The admin UI rewrites global_config.json (settings, template assignments) and
# credentials.json (default and per-host passwords) through saveJsonConfig(),
# which renames a temporary file over the target -- so www-data has to own them.
# auth_config.php holds the accounts, the roles and the API token digests, and
# nothing in the application ever writes it; it stays root's, and +t is what
# stops www-data replacing it anyway.
chown www-data:www-data "$ROOT"/config/*.json
chmod 0640 "$ROOT"/config/*.json

chown root:www-data "$ROOT/config/auth_config.php"
chmod 0640 "$ROOT/config/auth_config.php"

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

# redfish is optional -- only secure boot management needs it -- so nothing in
# this step may end the run. Every command that can fail sits in an if
# condition, where set -e does not apply and a failure selects the next branch
# instead of aborting.
#
# The apt attempt used to be guarded by "apt-cache show python3-redfish", which
# answers a different question than the one asked: apt can hold a record for a
# name that has no installation candidate. The guard passed, the install failed,
# and because it sat in the body of the if rather than its condition the whole
# script stopped here -- before nginx, before Kea, leaving a machine that looked
# installed and had neither. Just try the install and let the result decide.
if python3 -c 'import redfish' 2>/dev/null; then
    skip "the redfish module is already available"
elif [ "$SKIP_PACKAGES" != 1 ] \
     && apt-get install -y -qq --no-install-recommends python3-redfish >/dev/null 2>&1; then
    info "installed python3-redfish from apt"
else
    # Debian 13 marks the system Python externally managed (PEP 668), so a
    # venv is the supported way in. --system-site-packages keeps
    # python3-requests from apt visible rather than installing it twice.
    if [ ! -x "$ROOT/venv/bin/python3" ]; then
        python3 -m venv --system-site-packages "$ROOT/venv" 2>/dev/null \
            || warn "could not create $ROOT/venv"
    fi

    if [ -x "$ROOT/venv/bin/pip" ] \
       && "$ROOT/venv/bin/pip" install --quiet redfish 2>/dev/null; then
        info "installed redfish into $ROOT/venv"
    else
        warn "could not install the redfish module"
        note "Secure boot management needs the Python 'redfish' module:
       $ROOT/venv/bin/pip install redfish
     Everything else works without it."
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
    # PATH puts the venv first so the Python helpers find the redfish module.
    if grep -q '^env\[AUTODEPLOY_ROOT\]' "$POOL"; then
        skip "pool environment already set"
    else
        cat >> "$POOL" <<POOLEOF

; ---- hostdeployer (install.sh) ----
; The venv first, so python3 finds the redfish module when PHP shells out.
env[PATH] = $ROOT/venv/bin:/usr/local/bin:/usr/bin:/bin
env[AUTODEPLOY_ROOT] = $ROOT
POOLEOF
        info "set the helper PATH and AUTODEPLOY_ROOT in the FPM pool"
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
        -addext "subjectAltName=DNS:$HOSTNAME_FQDN,DNS:localhost,IP:$ADMIN_IP,IP:127.0.0.1" \
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

# Generated rather than copied and patched. Every listener binds an explicit
# address, and with one address the boot listener also redirects browsers to
# TLS while with two it must not -- that is a different shape, not a different
# string, and sed cannot add or remove a server block. Same reasoning as
# deploy/kea-config.sh.
NGINX_ARGS=(--deploy-ip "$SERVER_IP" --admin-ip "$ADMIN_IP"
            --fpm-socket "$FPM_SOCKET" --root "$ROOT")
if [ -n "$ADMIN_ALLOW" ]; then
    NGINX_ARGS+=(--admin-allow "$ADMIN_ALLOW")
fi

"$ROOT/deploy/nginx-config.sh" "${NGINX_ARGS[@]}" > /etc/nginx/sites-available/autodeploy

grep -q "unix:${FPM_SOCKET}" /etc/nginx/sites-available/autodeploy \
    || die "The generated site does not point at $FPM_SOCKET; see deploy/nginx-config.sh"

ln -sf /etc/nginx/sites-available/autodeploy /etc/nginx/sites-enabled/autodeploy

# Debian's default site owns "default_server" on port 80, which the boot chain
# needs: a firmware asking for /mboot.efi has no Host header worth matching.
if [ -e /etc/nginx/sites-enabled/default ]; then
    rm -f /etc/nginx/sites-enabled/default
    info "removed the default site (it owns default_server on :80)"
fi

# Same shape as the Kea check below: an if, so the retry that prints the error
# does not take the script down before die() explains it.
if ! nginx -t >/dev/null 2>&1; then
    printf '\n'
    nginx -t || true
    die "The nginx configuration was rejected; the error is above."
fi
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
        # Debian's default is ":69", every address. TFTP is part of the boot
        # chain and belongs on the deploy network only.
        sed -i "s#^TFTP_ADDRESS=.*#TFTP_ADDRESS=\"${SERVER_IP}:69\"#" /etc/default/tftpd-hpa
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
        # Not fatal: the appliance works without it, the admin UI just cannot
        # change DHCP. Aborting here would skip the Kea configuration below,
        # which matters more.
        if usermod -aG "$KEA_GROUP" www-data; then
            info "added www-data to the $KEA_GROUP group"
        else
            warn "could not add www-data to $KEA_GROUP"
            note "The admin UI needs write access to Kea's control socket to change
     DHCP settings. Grant it however this system expects."
        fi
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

# The one place this path is written. lib/kea.php defaults to the same value
# and deploy/kea-config.sh puts it in the configuration; all three have to
# agree or the admin UI talks to a socket nothing is listening on.
KEA_SOCKET=/run/kea/kea4-ctrl-socket

mkdir -p /etc/systemd/system/kea-dhcp4-server.service.d
cat > /etc/systemd/system/kea-dhcp4-server.service.d/10-socket-access.conf <<UNIT
# Let the admin UI reach the control socket. hostdeployer changes DHCP through
# Kea's API, which needs write access to the socket and traverse on its
# directory -- and nothing else. Installed by install.sh.
[Service]
RuntimeDirectory=kea
RuntimeDirectoryMode=0750
UMask=0007

# UMask alone is not enough. Kea creates the control socket during startup and
# on Debian 13 it comes out srwxr-x--- whatever the unit asks for -- the group
# gets r-x, and writing to a socket needs w, so the admin UI got "Permission
# denied" while every other check passed. The mode is therefore set explicitly
# once the socket exists.
#
# ExecStartPost runs as the service user, which owns the socket, so this needs
# no privilege of its own. The loop is because the socket appears during
# startup rather than before it, and the trailing exit 0 keeps a missing socket
# from failing the unit: DHCP still serves, only editing it from the UI stops
# working, and that is a degraded appliance rather than a broken one.
ExecStartPost=/bin/sh -c 'for _ in 1 2 3 4 5 6 7 8 9 10; do if [ -S $KEA_SOCKET ]; then chmod 0770 $KEA_SOCKET; exit 0; fi; sleep 0.5; done; exit 0'
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
    # Debian's kea-dhcp4 runs under an AppArmor profile, and the daemon reads
    # its configuration after dropping to the service user. A file written by
    # root and left root:root is not necessarily one it may open -- and the
    # refusal arrives as "Unable to open file", which reads like the file is
    # missing rather than like a permission problem.
    #
    # So keep whatever ownership and mode the package chose, rather than
    # guessing at them. The shipped file is the authority on what Kea expects.
    KEA_CONF_OWNER="root:$KEA_GROUP"
    KEA_CONF_MODE="0640"

    if [ -f /etc/kea/kea-dhcp4.conf ]; then
        KEA_CONF_OWNER="$(stat -c '%U:%G' /etc/kea/kea-dhcp4.conf)"
        KEA_CONF_MODE="$(stat -c '%a' /etc/kea/kea-dhcp4.conf)"
        cp -p /etc/kea/kea-dhcp4.conf "/etc/kea/kea-dhcp4.conf.bak.$(date +%Y%m%d%H%M%S)" \
            || warn "could not back up the existing kea-dhcp4.conf"
    fi

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

    # Restored, not invented: see the comment where these were captured.
    chown "$KEA_CONF_OWNER" /etc/kea/kea-dhcp4.conf || warn "could not set the owner on kea-dhcp4.conf"
    chmod "$KEA_CONF_MODE" /etc/kea/kea-dhcp4.conf
    info "wrote /etc/kea/kea-dhcp4.conf for $SUBNET/$PREFIX_LEN on $INTERFACE ($KEA_CONF_OWNER $KEA_CONF_MODE)"
fi

# An if rather than "|| { retry; die; }": a brace group after the final || is
# not exempt from set -e, so the retry that exists to show the error killed the
# script before die() could explain what the error meant.
# Validated as the service user, not as root. Debian confines kea-dhcp4 with an
# AppArmor profile that denies dac_read_search and dac_override, and /etc/kea is
# not traversable by uid 0 on its own permissions -- root normally gets there
# through exactly those capabilities. So a syntax check run as root fails on
# every file in that directory, including the one the package shipped, with
# "Unable to open file": a message that reads like the file is missing when in
# fact it was never opened.
#
# systemd starts the daemon as $KEA_USER, which owns the directory and needs no
# bypass. Checking as that user is both what works and what the check is
# actually meant to assert -- that the service can read what we just wrote.
kea_check() {
    if [ "$KEA_USER" != root ] && command -v runuser >/dev/null 2>&1; then
        runuser -u "$KEA_USER" -- kea-dhcp4 -t /etc/kea/kea-dhcp4.conf
    else
        kea-dhcp4 -t /etc/kea/kea-dhcp4.conf
    fi
}

if ! kea_check >/dev/null 2>&1; then
    printf '\n'
    kea_check || true
    die "Kea rejected /etc/kea/kea-dhcp4.conf.

     'Unable to open file' means Kea never opened it, rather than that the
     contents are wrong -- so look at access, not at JSON:
       ls -ld /etc/kea                          traversable by $KEA_USER?
       ls -l  /etc/kea/kea-dhcp4.conf           readable by $KEA_USER?
       dmesg | grep -i 'apparmor.*kea' | tail   denied, and which operation?
     Any other message is a parse error and names the line."
fi

systemctl enable --now kea-dhcp4-server >/dev/null 2>&1 || true
systemctl restart kea-dhcp4-server \
    || die "kea-dhcp4-server did not start. The configuration validated, so this
     is usually the interface: journalctl -u kea-dhcp4-server -n 30"
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
    local output

    if output="$("$@" 2>&1)"; then
        printf '    %s[ ok ]%s %s\n' "$C_OK" "$C_OFF" "$label"
    else
        printf '    %s[fail]%s %s\n' "$C_ERR" "$C_OFF" "$label"
        # The reason, not just the verdict. These checks capture an error the
        # moment it happens and used to throw it away, sending the operator to
        # journalctl for something the check already knew -- and in the case of
        # the Kea socket, something journalctl does not record at all.
        if [ -n "$output" ]; then
            printf '%s\n' "$output" | head -4 | sed "s/^/           $C_DIM/;s/\$/$C_OFF/"
        fi
        FAILED=1
    fi
}

check "php${PHP_VERSION}-fpm running"   systemctl is-active --quiet "php${PHP_VERSION}-fpm"
check "nginx running"                   systemctl is-active --quiet nginx
check "kea-dhcp4-server running"        systemctl is-active --quiet kea-dhcp4-server
# db() rather than storeLoadHosts(): storeLoadHostsConfig() catches Throwable
# and returns null, so the wrapper exits 0 whatever went wrong and this check
# could not fail. It passed on an installation whose database the web server
# could not open at all -- the failure only surfaced later, as an ERROR line in
# admin_dashboard.log that nobody was watching for.
#
# The write is the part that matters. WAL mode has to create -wal and -shm
# beside the database, so a directory the web server may read but not write
# fails here and not at open().
check "the web server can open and write the inventory" \
    sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/store.php';
        db()->exec('CREATE TABLE IF NOT EXISTS _install_check (x INTEGER)');
        db()->exec('DROP TABLE _install_check');"

# The admin UI rewrites both of these, and saveJsonConfig() does it by renaming
# a temporary file over the target -- which needs write on config/, not on the
# file. Checked separately because it fails for the same reason as the database
# and is just as silent: the settings page reports "saved" and nothing changes.
check "the web server can write the configuration" \
    sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/utils.php';
        \$c = loadJsonConfig(AUTODEPLOY_GLOBAL_CONFIG);
        exit(\$c !== null && saveJsonConfig(AUTODEPLOY_GLOBAL_CONFIG, \$c) ? 0 : 1);"
check "the stored ESXi password decrypts" sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/store.php'; exit(storeLoadCredentials('esxi')['root_password'] === '' ? 1 : 0);"
check "the dashboard answers on 443"    curl -skf -o /dev/null "https://$ADMIN_IP/admin/login.php"

# The point of a split installation, asserted rather than assumed. A listener
# that quietly binds every address looks identical from the admin network and
# is the whole finding.
if [ "$ADMIN_IP" != "$SERVER_IP" ]; then
    check "the dashboard is NOT reachable on the deploy network" \
        bash -c "! curl -sk --connect-timeout 3 -o /dev/null 'https://$SERVER_IP/admin/login.php'"
    check "the API is NOT reachable on the deploy network" \
        bash -c "! curl -sk --connect-timeout 3 -o /dev/null 'https://$SERVER_IP/api/v1/hosts'"
fi
check "the boot chain answers on 80"    bash -c "curl -sf -o /dev/null -w '%{http_code}' 'http://$SERVER_IP/boot.ipxe.php?mac=00:00:00:00:00:01' | grep -q 200"
check "the API rejects an unauthenticated call" bash -c "curl -sk -o /dev/null -w '%{http_code}' https://$ADMIN_IP/api/v1/hosts | grep -q 401"
# keaStatus() never throws -- it is written to render a page -- so it reports
# the reason in ['error'] instead. Passing that on is the difference between
# "the socket is not there" and "the web server may not write to it", which
# have different fixes and are otherwise indistinguishable from a [fail].
check "the admin UI can reach Kea's control socket" \
    sudo -u www-data "php${PHP_VERSION}" -r "putenv('AUTODEPLOY_ROOT=$ROOT'); require '$ROOT/lib/kea.php';
        \$status = keaStatus();
        if (!\$status['available']) { fwrite(STDERR, \$status['error'] . PHP_EOL); exit(1); }"

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

    Dashboard   https://$ADMIN_IP/admin/   (user: admin)
    API         https://$ADMIN_IP/api/v1/hosts
    Boot chain  http://$SERVER_IP/         DHCP pool $DHCP_START - $DHCP_END on $INTERFACE

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
