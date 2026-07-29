# hostdeployer

Automatiserad ESXi-provisionering: DHCP → iPXE → HTTP → kickstart, med ett
webbgränssnitt för att godkänna och konfigurera servrar.

## Dokumentation

- [`docs/CODE-REVIEW.md`](docs/CODE-REVIEW.md) — granskning och genomförda åtgärder
- [`docs/PLAN-via-go-port.md`](docs/PLAN-via-go-port.md) — plan för att hämta hem via_go:s styrkor
- [`docs/bootchain.md`](docs/bootchain.md) — bootkedjan steg för steg + felsökning
- [`docs/dhcp-kea.md`](docs/dhcp-kea.md) — migrering från ISC dhcpd till Kea

## Layout

```
lib/                 delade funktioner (utils.php, auth.php)
www/                 PHP-endpoints och admin-dashboard
ipxe/                boot.ipxe + ipxe.efi
esxi/<version>/      uppackat installationsmedia
templates/           kickstart-mallar
config/              konfiguration (*.example.* i git, live-filer ignoreras)
dhcp/                exempel för Kea och ISC dhcpd
scripts/             ilo_scanner.py, secure_boot_manager.py
logs/                loggfiler
```

## Installation

```bash
# 1. Lägg trädet på plats
install -d -m 0750 /srv/autodeploy
rsync -a --exclude .git ./ /srv/autodeploy/
install -d -m 0750 -o www-data -g www-data /srv/autodeploy/logs

# 2. Skapa konfigurationen från exempelfilerna
cd /srv/autodeploy/config
for f in global_config hosts credentials; do cp $f.example.json $f.json; done
cp auth_config.example.php auth_config.php
chmod 0640 *.json auth_config.php
chown root:www-data *.json auth_config.php
# Ersätt varje CHANGEME-värde.

# 3. Skapa ett adminlösenord
php /srv/autodeploy/lib/auth.php 'ditt-lösenord'   # klistra in hashen i auth_config.php

# 4. Webbserver
cp /srv/autodeploy/nginx.conf /etc/nginx/sites-available/autodeploy
ln -sf /etc/nginx/sites-available/autodeploy /etc/nginx/sites-enabled/
# Lägg ett certifikat på /etc/ssl/autodeploy/server.{crt,key}
nginx -t && systemctl reload nginx

# 5. DHCP (se docs/dhcp-kea.md)
install -m 0755 /srv/autodeploy/update_dhcp_config.sh /usr/local/bin/

# 6. Packa upp installationsmedia
mkdir -p /srv/autodeploy/esxi/8.0U3
# montera ISO:n och kopiera innehållet hit (boot.cfg måste finnas)
```

### sudo-regel

Admin-UI:t behöver köra DHCP-uppdateringen som root:

```
www-data ALL=(root) NOPASSWD: /usr/local/bin/update_dhcp_config.sh
```

Skriptet validerar alla sina argument själv och tar inga andra kommandon.

## Konfiguration

`AUTODEPLOY_ROOT` (miljövariabel, default `/srv/autodeploy`) styr var både
PHP-koden och Python-skripten letar. Inga IP-adresser finns i koden — allt
kommer från `config/global_config.json` respektive DHCP (`${next-server}`).

## Krav

- PHP 8.1+ med php-fpm
- nginx
- Kea DHCPv4 (rekommenderat) eller ISC dhcpd
- Python 3.9+ med `requests`; `redfish` krävs bara för secure boot-hanteringen
- UEFI-servrar (ESXi 8 stödjer inte legacy BIOS)
