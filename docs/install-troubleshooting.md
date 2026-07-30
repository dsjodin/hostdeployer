# install.sh — felsökning och kända fel

Dokumentet börjar med felet som gör att en installation avbryts halvvägs utan
att säga det, eftersom det förklarar samtliga tre symptom som rapporterats:
`python3-redfish`-felet, en nginx som ser orörd ut, och en Kea-konfiguration
som är Debians standardfil.

---

## 1. `python3-redfish` avbryter hela installationen (kritisk)

### Symptom

```
==> Python helpers
E: Package 'python3-redfish' has no installation candidate
```

…och sedan tar utskriften slut. Efteråt:

* `/etc/nginx/sites-enabled/` innehåller fortfarande Debians `default`
* `/etc/kea/kea-dhcp4.conf` är Debians standardfil
* `/etc/php/8.4/fpm/conf.d/99-hostdeployer.ini` saknas
* inget TLS-certifikat under `/etc/ssl/autodeploy/`
* verifieringssteget (`[ ok ] / [fail]`-listan) kördes aldrig

### Orsak

`install.sh:28` sätter `set -euo pipefail`. Python-steget ser ut så här
(`install.sh:538-560`):

```bash
step "Python helpers"

if ! python3 -c 'import redfish' 2>/dev/null; then
    if apt-cache show python3-redfish >/dev/null 2>&1 && [ "$SKIP_PACKAGES" != 1 ]; then
        apt-get install -y -qq --no-install-recommends python3-redfish >/dev/null   # <-- här
        info "installed python3-redfish from apt"
    else
        # venv-fallback
    fi
fi
```

Två saker går fel samtidigt:

1. **Grinden svarar fel.** `apt-cache show python3-redfish` returnerar 0 på den
   här maskinen — apt har en post för namnet — trots att paketet inte har någon
   installerbar kandidat i trixie. Grinden är alltså sann och venv-fallbacken,
   som är det som faktiskt fungerar på Debian 13, hoppas över.

2. **Felet är dödligt.** `apt-get install` står i *kroppen* av `if`-satsen.
   `set -e` gäller inte kommandon i ett `if`-**villkor**, men gäller kommandon i
   dess **kropp**. apt avslutar med 100 → skriptet avslutas omedelbart.

Steget ligger på rad 538. nginx-steget ligger på rad 654, Kea på rad 774. Därför
körs varken nginx- eller Kea-konfigurationen.

### Åtgärd i koden

Flytta `apt-get` in i villkoret, där ett misslyckande väljer nästa gren i
stället för att avsluta skriptet, och släng `apt-cache`-grinden — den avgör inte
det den utger sig för att avgöra:

```bash
step "Python helpers"

if python3 -c 'import redfish' 2>/dev/null; then
    skip "the redfish module is already available"
elif [ "$SKIP_PACKAGES" != 1 ] \
     && apt-get install -y -qq --no-install-recommends python3-redfish >/dev/null 2>&1; then
    info "installed python3-redfish from apt"
else
    # Debian 13 markerar system-Python externally managed (PEP 668), så en venv
    # är den stödda vägen in. --system-site-packages behåller python3-requests
    # från apt synligt i stället för att installera det två gånger.
    if [ ! -x "$ROOT/venv/bin/python3" ]; then
        python3 -m venv --system-site-packages "$ROOT/venv" || warn "could not create $ROOT/venv"
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
```

Poängen är att varje kommando som *får* misslyckas står i ett `if`-villkor eller
följs av `|| warn`. `redfish` är en valfri beroende — bara secure boot-hanteringen
behöver den — och ska aldrig kunna stoppa en installation.

### Så tar du dig vidare nu, utan patch

Paketsteget hann köras (det ligger på rad 315, före avbrottet), liksom
konfigurationen och hemligheterna. Kör om med `--skip-packages`: då blir grinden
`[ "$SKIP_PACKAGES" != 1 ]` falsk, venv-grenen väljs, och ett misslyckat
`pip install` varnar i stället för att avbryta.

```bash
sudo ./install.sh --skip-packages \
     --interface <provisioneringsinterface> --server-ip <provisioneringsadress> \
     --dhcp-range <start>-<slut> --netmask 255.255.255.0 \
     --gateway <gw> --dns <dns>
```

Körningen är idempotent: `global_config.json`, `credentials.json`,
`auth_config.php`, `secret.key` och `api_local_token` finns redan och rörs inte.
Skriptet frågar ändå efter admin-, iLO- och ESXi-lösenord (se
[papperscut 3](#4-mindre-fel-i-samma-skript)) — svara vad som helst, värdena
kastas eftersom filerna redan finns.

---

## 2. "nginx.conf ser ut som Debians standard"

`/etc/nginx/nginx.conf` **ska** vara Debians standardfil. hostdeployer levererar
en *site*, inte en huvudkonfiguration. Den innehåller
`include /etc/nginx/sites-enabled/*;`, och det är där vår konfiguration hamnar.

Rätt kontroll:

```bash
ls -l /etc/nginx/sites-enabled/
# ska innehålla:  autodeploy -> /etc/nginx/sites-available/autodeploy
# ska INTE innehålla: default

grep -n 'listen\|autodeploy' /etc/nginx/sites-available/autodeploy | head
```

Saknas `autodeploy` där kom install.sh aldrig till rad 654 — se avsnitt 1.

`nginx -t` säger ingenting om saken: den validerar den konfiguration som råkar
vara aktiv, och Debians default är giltig.

Två saker install.sh gör i det steget som är lätta att missa manuellt:

* `sed` byter platshållaren `unix:/var/run/php/php-fpm.sock` mot den versionerade
  Debian-sökvägen `/run/php/php8.4-fpm.sock`. Kopierar du `nginx.conf` för hand
  måste du göra samma sak, annars går varje PHP-request till en socket som inte
  finns.
* Debians `default`-site tas bort. Den äger `default_server` på port 80, och
  bootkedjan behöver den — firmware som hämtar `/mboot.efi` skickar ingen
  Host-header värd att matcha på.

---

## 3. "Kea-konfigurationen under /etc/kea ser ut som standard"

Den skrivs av install.sh (rad 774-805) genom `deploy/kea-config.sh`, och har en
igenkännbar header:

```bash
head -3 /etc/kea/kea-dhcp4.conf
# // Kea DHCPv4 configuration for hostdeployer.
# //
# // Generated by deploy/kea-config.sh on ...
```

Står det inte så kom installationen inte dit. Bygg den för hand — skriptet
skriver bara till stdout och rör ingen tjänst:

```bash
sudo /srv/autodeploy/deploy/kea-config.sh \
     --interface ens192.20 --server-ip 10.20.0.2 \
     --subnet 10.20.0.0/24 --pool "10.20.0.100 - 10.20.0.200" \
     --gateway 10.20.0.1 --dns 10.20.0.53 --domain lab.local \
     > /tmp/kea-dhcp4.conf

kea-dhcp4 -t /tmp/kea-dhcp4.conf            # validera FÖRST
sudo cp -p /etc/kea/kea-dhcp4.conf /etc/kea/kea-dhcp4.conf.bak
sudo install -m 0644 /tmp/kea-dhcp4.conf /etc/kea/kea-dhcp4.conf
sudo systemctl restart kea-dhcp4-server
```

Och det som install.sh gör *utöver* filen, och som annars glöms bort — utan det
kan admin-UI:t inte ändra DHCP alls:

```bash
# www-data behöver skriva på kontrollsocketen
sudo usermod -aG "$(id -gn "$(systemctl show -p User --value kea-dhcp4-server)")" www-data

# och traversera katalogen den ligger i
sudo mkdir -p /etc/systemd/system/kea-dhcp4-server.service.d
sudo tee /etc/systemd/system/kea-dhcp4-server.service.d/10-socket-access.conf >/dev/null <<'UNIT'
[Service]
RuntimeDirectory=kea
RuntimeDirectoryMode=0750
UMask=0007
UNIT
sudo systemctl daemon-reload
sudo systemctl restart kea-dhcp4-server
sudo systemctl restart php8.4-fpm     # en körande worker behåller sina grupper
```

Kontrollera att det tog:

```bash
sudo -u www-data php -r "putenv('AUTODEPLOY_ROOT=/srv/autodeploy');
  require '/srv/autodeploy/lib/kea.php'; var_dump(keaStatus());"
```

---

## 3b. `error: PHP 8.4 is missing the mbstring extension`

`install.sh:331-333`

```bash
for ext in pdo_sqlite curl mbstring; do
    "php${PHP_VERSION}" -m 2>/dev/null | grep -qx "$ext" || die "PHP $PHP_VERSION is missing the $ext extension"
done
```

### Kontrollen är för sträng

**`mbstring` används inte någonstans i trädet.** Ingen `mb_*`-funktion finns i
`lib/`, `www/`, `scripts/` eller `tests/`. Detsamma gäller `php-xml`, som står i
`PACKAGES` men vars `DOMDocument`/`SimpleXML` inte anropas någonstans. All
strängbehandling går genom `preg_*`, `str_*` och `htmlspecialchars(…, 'UTF-8')`,
som alla ligger i `pcre`/`Core`/`standard`.

De tre tillägg som faktiskt krävs är:

| Tillägg | Används av |
|---|---|
| `sodium` | `lib/secrets.php` — XChaCha20-Poly1305 |
| `pdo_sqlite` | `lib/db.php` — hela inventariet |
| `curl` | reserverad för utgående anrop; inget i trädet anropar den idag heller |

### Åtgärd i koden

```bash
# mbstring och xml stod i listan utan att användas. Kravlistan ska vara
# det applikationen faktiskt laddar, annars stoppas en installation av ett
# tillägg ingen kod anropar.
for ext in pdo_sqlite; do
    "php${PHP_VERSION}" -m 2>/dev/null | grep -qx "$ext" \
        || die "PHP $PHP_VERSION is missing the $ext extension.
     Install it with: apt install php${PHP_VERSION}-sqlite3"
done
```

Notera också att meddelandet idag inte säger vad man ska göra. Sodium-kontrollen
strax ovanför gör det — den namnger paketet och förklarar varför det inte går att
fortsätta utan. Varje `die` i skriptet bör hålla den standarden; en avbruten
installation som inte säger nästa steg kostar mer än raden den sparade.

Ta samtidigt bort `php${PHP_VERSION}-mbstring` och `-xml` ur `PACKAGES`
(`install.sh:302`), eller behåll dem med en kommentar om vad de är avsedda för.

### Så tar du dig förbi den nu

```bash
# Är paketet installerat?
dpkg -l | grep php8.4-mbstring

# Om ja: det är installerat men inte aktiverat för CLI-SAPI:n
sudo phpenmod -v 8.4 mbstring && sudo systemctl restart php8.4-fpm

# Om nej:
sudo apt install php8.4-mbstring

# Kontrollera:
php8.4 -m | grep -x mbstring
```

`--skip-packages` hoppar över apt men **inte** över extensionskontrollerna, vilket
i sig är rätt — men det gör att flaggan inte hjälper mot just det här felet.

---

## 3c. `Syntax check failed with: Unable to open file /etc/kea/kea-dhcp4.conf`

Filen finns, är 0644, och `head` läser den utan problem som root. Ändå säger
`kea-dhcp4 -t` att den inte kan öppnas — och det gäller **varje** fil i
`/etc/kea`, inklusive den som paketet levererade.

### Orsak

Tre saker tillsammans:

1. Debian 13 confinar `/usr/sbin/kea-dhcp4` med en AppArmor-profil som nekar
   `dac_read_search` och `dac_override`. I `dmesg`:

   ```
   apparmor="DENIED" operation="capable" profile="kea-dhcp4" capname="dac_read_search"
   apparmor="DENIED" operation="capable" profile="kea-dhcp4" capname="dac_override"
   ```

2. `/etc/kea` är inte traverserbar för uid 0 på sina egna rättigheter. Root tar
   sig normalt dit genom precis de två capabilities som nekas ovan.

3. Profilen tillåter sökvägen — `/etc/kea/ r,` och `/etc/kea/** r,` står i
   `/etc/apparmor.d/usr.sbin.kea-dhcp4`. Det är alltså inte AppArmors filregler
   som stoppar läsningen, utan DAC-kontrollen som sker före dem, med bypassen
   bortopererad.

Daemonen påverkas inte: systemd startar den som `_kea`, som äger katalogen och
aldrig behöver någon bypass. Det är bara en syntaxkontroll körd som root som
träffar det här — vilket är exakt vad `install.sh` gjorde.

Det är också därför felet är så förvirrande: "Unable to open file" låter som att
filen saknas, men den öppnades aldrig. Ett parse-fel hade namngett raden.

### Åtgärd i koden

Validera som tjänsteanvändaren i stället för som root:

```bash
runuser -u "$KEA_USER" -- kea-dhcp4 -t /etc/kea/kea-dhcp4.conf
```

Det är dessutom det kontrollen är till för att påstå: att *tjänsten* kan läsa
det vi nyss skrev. En kontroll som lyckas som root och sedan låter daemonen
misslyckas hade varit sämre än ingen kontroll alls.

### Så testar du för hand

```bash
ls -ld /etc/kea
sudo -u _kea kea-dhcp4 -t /etc/kea/kea-dhcp4.conf
```

Vill du ändå kunna köra den som root, lägg till en lokal override — profilen
inkluderar redan `#include <local/usr.sbin.kea-dhcp4>`:

```bash
sudo tee /etc/apparmor.d/local/usr.sbin.kea-dhcp4 >/dev/null <<'EOF'
capability dac_read_search,
EOF
sudo apparmor_parser -r /etc/apparmor.d/usr.sbin.kea-dhcp4
```

Men det ger tillbaka en bypass som paketet medvetet tagit bort. Att köra
kontrollen som rätt användare är det bättre svaret.

---

## 4. Mindre fel i samma skript

Alla nedan är samma sorts problem som avsnitt 1: `set -e` plus ett kommando som
får misslyckas i verkligheten men inte i skriptet.

| # | Rad | Kommando | Vad som händer när det failar | Åtgärd |
|---|---|---|---|---|
| 1 | 738 | `usermod -aG "$KEA_GROUP" www-data` | avbryter före Kea-konfigurationen | `|| warn "…"` |
| 2 | 811 | `systemctl restart kea-dhcp4-server` | avbryter före verifieringssteget, som är det enda som skulle ha rapporterat varför | `|| die "Kea startade inte: journalctl -u kea-dhcp4-server"` |
| 3 | 277-292 | `prompt ADMIN_PASSWORD` m.fl. | frågar efter hemligheter *före* kontrollen av om filerna redan finns, och `die`:ar om de utelämnas — en omkörning kräver alltså lösenord den sedan kastar | flytta prompten in i `if [ ! -f … ]`-grenen |
| 4 | 709 | `sed -i` på `/etc/default/tftpd-hpa` | avbryter om filen saknas (kontrollen finns, men `systemctl restart tftpd-hpa` på 711 är bara `|| warn`) | konsekvent `|| warn` |
| 5 | 34 | `readonly PHP_VERSION=8.4` | `die` på en maskin där PHP heter något annat, trots att README och `composer.json` säger 8.1+ | autodetektera: första träffen i `/etc/php/*/fpm/`, 8.4 som preferens |
| 6 | — | ingen `trap ERR` | ett avbrott säger inte vilket steg som fallerade | se nedan |

Ett `trap` gör hela klassen av fel självförklarande:

```bash
CURRENT_STEP="starting"
step() { CURRENT_STEP="$*"; printf '\n%s==>%s %s\n' "$C_OK" "$C_OFF" "$*"; }

trap 'rc=$?; [ $rc -eq 0 ] || printf "\n%serror:%s aborted during \"%s\" at line %s: %s (exit %s)\n" \
      "$C_ERR" "$C_OFF" "$CURRENT_STEP" "$LINENO" "$BASH_COMMAND" "$rc" >&2' EXIT
```

Med det hade den här felsökningen varit en rad utskrift i stället för ett
dokument.

---

## 5. Efterkontroll

Verifieringsblocket i slutet av install.sh (rad 826-847) är bra men körs bara som
en del av en lyckad körning. Det tål att brytas ut till ett eget skript,
`deploy/verify.sh`, som kan köras när som helst:

```bash
systemctl is-active php8.4-fpm nginx kea-dhcp4-server
test -L /etc/nginx/sites-enabled/autodeploy && echo "nginx-site ok"
grep -q hostdeployer /etc/kea/kea-dhcp4.conf && echo "kea-config ok"
curl -skf -o /dev/null https://127.0.0.1/admin/login.php && echo "dashboard ok"
curl -sf  -o /dev/null 'http://127.0.0.1/boot.ipxe.php?mac=00:00:00:00:00:01' && echo "bootkedja ok"
curl -sk -o /dev/null -w '%{http_code}\n' https://127.0.0.1/api/v1/hosts   # ska vara 401
```

---

## Sammanfattning av föreslagna ändringar

| Prio | Fil | Ändring |
|---|---|---|
| P0 | `install.sh:538-560` | flytta `apt-get install python3-redfish` in i `if`-villkoret, ta bort `apt-cache`-grinden |
| P0 | `install.sh:28` | lägg till `trap … EXIT` som namnger steget vid avbrott |
| P1 | `install.sh:738, 811, 709` | `|| warn` / `|| die` med förklaring på varje kommando som kan misslyckas |
| P1 | `install.sh:277-292` | fråga inte efter hemligheter som kommer att kastas |
| P2 | `install.sh:34` | autodetektera PHP-version i stället för `readonly 8.4` |
| P2 | `deploy/verify.sh` | bryt ut verifieringen så den kan köras fristående |
