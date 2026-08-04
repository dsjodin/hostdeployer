# hostdeployer

Automatiserad ESXi-provisionering. Servrar hittas på iLO/iDRAC-nätet över
Redfish, godkänns i webbgränssnittet, och installeras sedan genom
DHCP → iPXE → mboot → kickstart. Webbgränssnitt och REST-API för godkännande
och konfiguration.

Deployern sitter på tre nät — admin, iLO/iDRAC och ESXi mgmt/deploy — och
admin-gränssnittet svarar bara på det första. Se
[`docs/network-segmentation.md`](docs/network-segmentation.md).

## Dokumentation

- [`docs/CODE-REVIEW.md`](docs/CODE-REVIEW.md) — granskning och genomförda åtgärder
- [`docs/CODE-REVIEW-2026-07.md`](docs/CODE-REVIEW-2026-07.md) — kodgranskning juli 2026
- [`docs/SECURITY-REVIEW-2026-07.md`](docs/SECURITY-REVIEW-2026-07.md) — säkerhetsgranskning juli 2026
- [`docs/network-segmentation.md`](docs/network-segmentation.md) — admin-VLAN och provisionerings-VLAN
- [`docs/install-troubleshooting.md`](docs/install-troubleshooting.md) — när `install.sh` inte kommer i mål
- [`docs/PLAN-via-go-port.md`](docs/PLAN-via-go-port.md) — plan för att hämta hem via_go:s styrkor
- [`docs/bootchain.md`](docs/bootchain.md) — bootkedjan steg för steg + felsökning
- [`docs/dhcp.md`](docs/dhcp.md) — DHCP med Kea: klasser, kontroll-API, felsökning

## Layout

```
lib/                 delade funktioner (utils.php, auth.php)
www/                 PHP-endpoints och admin-dashboard
ipxe/                boot.ipxe + ipxe.efi
esxi/<version>/      uppackat installationsmedia
templates/           kickstart-mallar
config/              konfiguration + värdinventariet (SQLite), ignoreras i git
deploy/              kea-config.sh (genererar Kea-konfigurationen)
scripts/             ilo_scanner.py, secure_boot_manager.py
logs/                loggfiler
```

## Installation

Debian 13 (trixie). PHP 8.4, nginx och Kea 2.6 finns alla i main, så ingenting
här behöver en tredjeparts apt-källa.

```bash
git clone https://github.com/dsjodin/hostdeployer.git
cd hostdeployer
sudo ./install.sh
```

Skriptet frågar efter nätverk och lösenord, eller tar dem som flaggor:

```bash
sudo ./install.sh --interface ens192 --server-ip 10.0.0.2 \
     --dhcp-range 10.0.0.100-10.0.0.200 --netmask 255.255.255.0 \
     --gateway 10.0.0.1 --dns 10.0.0.53 --yes
```

Det installerar paketen, lägger trädet under `/srv/autodeploy`, konfigurerar
PHP-FPM och nginx med ett certifikat, skriver Kea-konfigurationen, genererar
krypteringsnyckeln och API-token, och verifierar att allt svarar. Det går att
köra om — befintliga hemligheter, konfiguration och värdinventariet rörs inte.

<details>
<summary>Manuellt, om du hellre gör stegen själv</summary>

```bash
# 1. Lägg trädet på plats
install -d -m 0750 /srv/autodeploy
rsync -a --exclude .git --exclude vendor --exclude tests ./ /srv/autodeploy/
install -d -m 0750 -o www-data -g www-data /srv/autodeploy/logs

# 2. Skapa konfigurationen från exempelfilerna
cd /srv/autodeploy/config
for f in global_config credentials; do cp $f.example.json $f.json; done
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

# 5. DHCP (se docs/dhcp.md)
apt install kea-dhcp4-server kea-ctrl-agent
/srv/autodeploy/deploy/kea-config.sh --interface ens192 --server-ip 10.0.0.2 \
    --subnet 10.0.0.0/24 --pool "10.0.0.100 - 10.0.0.200" \
    --gateway 10.0.0.1 --dns 10.0.0.53 > /etc/kea/kea-dhcp4.conf
kea-dhcp4 -t /etc/kea/kea-dhcp4.conf && systemctl enable --now kea-dhcp4-server
# www-data behöver skrivrättighet på /run/kea/kea4-ctrl-socket

# 6. Installationsmedia
# Ladda upp ISO:n under Settings > ESXi Versions i admin-UI:t, eller:
#   curl -H "Authorization: Bearer $TOKEN" -F version=8.0U3 \
#        -F sha256=<checksumma> -F image=@VMware-ESXi-8.0U3.iso \
#        https://<server>/api/v1/images
# Kräver bsdtar, 7z eller xorriso på servern.
```

</details>

### Värdinventariet

Hostarna ligger i SQLite: `config/autodeploy.db`. Databasen och dess schema
skapas automatiskt första gången något läser eller skriver — inget
installationssteg behövs, och det finns ingen `hosts.json` längre.

Konfigurationen (`global_config.json`) och credentials är kvar som filer. De är
små nästlade dokument som fylls i för hand vid installation, och en databas
hade tagit bort den möjligheten utan att ge något tillbaka.

```bash
sqlite3 /srv/autodeploy/config/autodeploy.db \
  'SELECT mac, hostname, deployment_status FROM hosts;'
```

Säkerhetskopiera `config/` i sin helhet — databasen, krypteringsnyckeln och
konfigurationen hör ihop.

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
`config/credentials.json` direkt. När admin-UI:t startar
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

### DHCP

Admin-UI:t ändrar DHCP genom **Keas kontroll-API**, inte genom att skriva om
`/etc/kea/kea-dhcp4.conf`. `config-test` validerar, `config-set` tillämpar på
den körande servern utan omstart, `config-write` persisterar.

Det betyder att webbservern **inte kör någonting som root**. Den behöver bara
skrivrättighet på kontrollsocketen, vilket `install.sh` ger genom att lägga
`www-data` i Keas grupp. Den tidigare sudo-regeln tas bort om den finns kvar.

Bara Kea stödjs. ISC dhcpd är EOL sedan december 2022, finns inte i Debian 13,
och saknar det API som gör det ovanstående möjligt.

Behöver du bygga om `/etc/kea/kea-dhcp4.conf` från grunden — efter en trasig
handredigering, eller när Kea inte startar och alltså inte har någon socket att
prata med — genererar `deploy/kea-config.sh` den. Se [`docs/dhcp.md`](docs/dhcp.md).

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
| `GET` `POST` | `/api/v1/images` | read / settings |
| `GET` `DELETE` | `/api/v1/images/{version}` | read / settings |
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
`shellcheck` på `install.sh` och `deploy/`, och `ruff` på `scripts/`.

## Krav

- PHP 8.1+ med php-fpm
- nginx
- Kea DHCPv4 2.6+ (ISC dhcpd stödjs inte)
- Python 3.9+ med `requests`; `redfish` krävs bara för secure boot-hanteringen
- `bsdtar`, `7z` eller `xorriso` för ISO-uppladdning (valfritt — media kan
  fortfarande packas upp för hand på servern)
- UEFI-servrar (ESXi 8 stödjer inte legacy BIOS)
