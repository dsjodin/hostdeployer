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
rsync -a --exclude .git --exclude vendor --exclude tests ./ /srv/autodeploy/
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

### Krypteringsnyckel

Lösenorden i `config/credentials.json` — ESXi-rootlösenordet varje host
installeras med, och iLO-kontot — lagras krypterade med XChaCha20-Poly1305.
Nyckeln skapas automatiskt vid första skrivningen, men kör den explicit så du
vet att den finns innan något behöver den:

```bash
php /srv/autodeploy/lib/secrets.php --encrypt-credentials
```

> **Säkerhetskopiera `config/secret.key`.** Utan den är varje lagrat lösenord
> oåterkalleligt oläsbart. Filen skapas med rättigheterna `0600` och ligger i
> `.gitignore`.
>
> En skadad nyckel *avvisas* i stället för att ersättas — koden vägrar hellre
> starta än tyst genererar en ny som gör alla lagrade lösenord obrukbara.

En installation som föregår krypteringen fortsätter fungera: klartextvärden
läses som de är, loggas som en varning, och skrivs krypterade nästa gång filen
sparas. Kommandot ovan gör det direkt.

### API-token

Python-skripten läser och skriver via REST-API:t i stället för att öppna
`config/hosts.json` och `config/credentials.json` direkt. När admin-UI:t startar
en iLO-skanning behöver den processen en token:

```bash
php /srv/autodeploy/lib/api_auth.php --local
chown root:www-data /srv/autodeploy/config/api_local_token
# klistra in den utskrivna 'local-helpers'-posten i config/auth_config.php
```

För extern automation, skapa en egen token per konsument:

```bash
php /srv/autodeploy/lib/api_auth.php min-automation operator
```

Bara digesten hamnar i `auth_config.php` — tappar du token får du skapa en ny.

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

## REST-API

Allt admin-UI:t kan göra går också att automatisera. API:t svarar **bara över
TLS** — port 80 tillhör bootkedjan, vars klienter är firmware utan credentials,
och en token ska inte korsa den.

```bash
curl -H "Authorization: Bearer $TOKEN" https://<server>/api/v1/hosts
```

| Metod | Väg | Behörighet |
|---|---|---|
| `GET` `POST` | `/api/v1/hosts` | read / write |
| `GET` `PATCH` `DELETE` | `/api/v1/hosts/{mac}` | read / write |
| `GET` | `/api/v1/hosts/{mac}/status` | read |
| `POST` | `/api/v1/hosts/{mac}/approve` | approve |
| `POST` | `/api/v1/hosts/{mac}/reinstall` | approve |
| `PATCH` | `/api/v1/hosts/{mac}/secure-boot` | write |
| `POST` | `/api/v1/hosts/discovered` | write |
| `GET` `PUT` | `/api/v1/credentials/{ilo\|esxi}` | settings |
| `POST` | `/api/v1/scan` | scan |
| `GET` | `/api/v1/versions` | read |

MAC-adressen i sökvägen normaliseras, så `00-0C-29-91-CF-EB` och
`00:0c:29:91:cf:eb` är samma host.

`/credentials` kräver `settings`, som bara `admin` har som standard: den delar
ut ESXi-rootlösenord. `/hosts/discovered` tar emot resultat från en
hårdvaruskanning och slår ihop dem serversidan — matchning på serienummer
först, sedan på känd MAC, och en befintlig `mac_address` skrivs aldrig över.

## Utveckling

Testerna och den statiska analysen är utvecklingsberoenden och installeras
aldrig på servern — `rsync`-raden ovan utesluter både `vendor/` och `tests/`.

```bash
composer install

composer test      # PHPUnit
composer lint      # PHPStan level 5
composer syntax    # php -l över hela trädet
```

Testerna täcker de rena funktionerna i `lib/` — MAC-normalisering,
nätmaskvalidering, sökvägsskydd, mallrendering, `boot.cfg`-parsning och
lösenordshashning. De rör varken filsystemet under `/srv/autodeploy`,
konfigurationen eller nätverket; `tests/bootstrap.php` pekar om
`AUTODEPLOY_ROOT` till en temporär katalog som städas efter körningen.

Samma kontroller körs i CI (`.github/workflows/ci.yml`), tillsammans med
`shellcheck` på `update_dhcp_config.sh` och `ruff` på `scripts/`.

## Krav

- PHP 8.1+ med php-fpm
- nginx
- Kea DHCPv4 (rekommenderat) eller ISC dhcpd
- Python 3.9+ med `requests`; `redfish` krävs bara för secure boot-hanteringen
- UEFI-servrar (ESXi 8 stödjer inte legacy BIOS)
