# Kodgranskning och åtgärder — hostdeployer

Granskning av hela kodbasen (PHP, Python, iPXE, DHCP, nginx, kickstart-mallar)
med tillhörande rättningar. Dokumentet är uppdelat i:

1. [Kraschbuggar](#1-kraschbuggar) — kod som inte gick att köra
2. [Säkerhet](#2-säkerhet)
3. [Datakorruption och race conditions](#3-datakorruption-och-race-conditions)
4. [Bootkedjan](#4-bootkedjan)
5. [DHCP: ISC dhcpd → Kea](#5-dhcp-isc-dhcpd--kea)
6. [iPXE eller ren HTTP/HTTPS?](#6-ipxe-eller-ren-httphttps)
7. [TFTP](#7-tftp)
8. [Kodkvalitet och prestanda](#8-kodkvalitet-och-prestanda)
9. [Kvar att göra](#9-kvar-att-göra)

---

## 1. Kraschbuggar

Tre funktioner anropades men fanns inte definierade någonstans i trädet. Varje
anrop gav `Fatal error: Call to undefined function` och en vit sida.

| Funktion | Anropades från | Effekt |
|---|---|---|
| `processScanActions()` | `admin_dashboard.php:139` | **Hela Hardware Scan-fliken** var död. Både "Start iLO Scan" och "Manual MAC Registration" kraschade. |
| `processReinstallHostAction()` | `hosts.php:670` | Knappen "Reinstall" kraschade. |
| `extractHostnameFromFQDN()` | `host_functions.php:33` | Krasch när en host sparades utan explicit hostname. |

Alla tre är nu implementerade (`www/scan.php`, `www/host_functions.php`,
`lib/utils.php`).

**Övriga kraschande/döda vägar:**

- `settings.php` hade **två identiska `case 'save_default_credentials':`** i
  samma `switch`. Den andra var oåtkomlig död kod. Borttagen.
- `generate_kickstart.php` avvisade allt som inte var `approved`/`deploying`
  *innan* den kontrollerade `=== 'pending'`. Hela "väntar på godkännande"-grenen
  var därmed oåtkomlig — servrar som väntade fick ett felmeddelande + `reboot`
  och hamnade i en oändlig ominstallationsloop. Ordningen är omvänd nu.
- `admin_ui.php` laddade **aldrig Bootstrap CSS**, trots att hela dashboarden är
  byggd på Bootstrap 5-klasser (`card`, `row`, `col-md-*`, `nav-tabs`, `modal`).
  Filen fanns i `www/css/bootstrap.min.css` men var inte länkad. Nu länkad.
- `hosts.php` satte `display_errors=1` + `error_reporting(E_ALL)` på rad 2–4.
  Eftersom filen `require_once`:as av dashboarden slog det igenom på *hela*
  admingränssnittet och skrev PHP-varningar rakt in i HTML. Borttaget.
- `nginx.conf` pekade `/deployment-complete.php` (bindestreck) mot en fil som
  heter `deployment_complete.php` (understreck) → 404.

---

## 2. Säkerhet

### 2.1 Godtycklig filskrivning → RCE (kritisk)

`templates.php` byggde filsökvägar genom ren strängkonkatenering av
användarindata på **nio ställen**:

```php
$templateFile = $postData['template_file'];   // helt ovaliderad
$templatePath = "$templatesDir/$templateFile";
...
saveTemplateFile($templatePath, $content, $createBackup);
```

En inloggad användare (även rollen `operator`) kunde därmed:

- **skriva** valfri fil: `template_file=../www/shell.php` +
  `template_content=<?php system($_GET['c']); ?>` → webshell → RCE
- **läsa** valfri fil: `?edit=../config/credentials.json` visades i textarean
- **radera** valfri fil: `delete_template` / `delete_backup`
- **ladda upp** till valfri sökväg via `upload_filename`

Åtgärd: `isValidTemplateName()` / `isValidBackupName()` /
`resolveTemplatePath()` / `resolveBackupPath()` — filnamn måste matcha
`^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.cfg$` och vara identiska med sitt
`basename()`. Även `update_template_assignments` valideras nu, annars kunde man
peka `kickstart_templates.standard` mot `/etc/shadow` och läsa den via
`/ks.cfg`. Verifierat mot 8 angreppssträngar — samtliga avvisas.

En generell `safePathJoin()` finns i `lib/utils.php` för framtida bruk.

### 2.2 Inbyggda fallback-inloggningar (kritisk)

`login.php` föll tillbaka på **hårdkodade `admin` / `password`** om
`auth_config.php` saknades *eller* var felformaterad — alltså precis i det läge
där man minst vill ha en öppen dörr. Borttaget helt: saknas konfigurationen går
inloggning inte att genomföra.

`www/simple_auth.php` innehöll `admin` / `password` i klartext. Filen användes
inte längre. Borttagen.

### 2.3 CSRF (allvarlig)

Ingen enda POST i hela admingränssnittet hade CSRF-skydd. Alla tillståndsändrande
åtgärder — godkänna host, radera host, skriva om DHCP-konfigurationen, radera
mall, ändra lösenord — kunde triggas av vilken sida som helst som operatören
råkade besöka.

Åtgärd: `csrfToken()` / `csrfField()` / `verifyCsrfToken()` i `lib/auth.php`,
en central kontroll i `admin_dashboard.php` som avvisar **alla** POST utan giltig
token, och `csrfField()` i samtliga formulär.

### 2.4 Session

| Problem | Åtgärd |
|---|---|
| Ingen `session_regenerate_id()` vid inloggning → session fixation | Rotation i `establishSession()` (verifierat) |
| Cookie utan `HttpOnly`, `Secure`, `SameSite` | Sätts i `startAdminSession()` |
| `session_destroy()` utan att rensa cookien | `destroySession()` nollar cookien |
| Obegränsade inloggningsförsök | Throttling efter 5 misslyckade försök |
| Användaruppräkning via svarstid (`password_verify` kördes bara för kända användare) | Dummy-hash körs alltid; uppmätt tidsskillnad nu ≈ 0 % |

### 2.5 XSS

- `hosts.php` interpolerade hostdata i `onclick="editHost('...')"` — enkla
  citattecken i ett hostnamn bröt ut i JS-kontext (`htmlspecialchars()` hade
  `ENT_COMPAT` som default före PHP 8.1). Ersatt med `data-*`-attribut som
  JSON + delegerad event-hanterare.
- `admin_ui.php` gjorde `logContent.textContent = data` och därefter
  `logContent.innerHTML = ...` — vilket återinförde precis den markup som
  `textContent` nyss escapat. Loggarna innehåller PXE-klientlevererade värden.
  Ersatt med en DOM-baserad `renderLogLines()`.
- `serialDisplay.innerHTML = serial` → `textContent`.
- Alla `htmlspecialchars()` bytta mot `h()` med explicit `ENT_QUOTES`.

### 2.6 Loggförfalskning

MAC-adresser från query-strängen skrevs oescapade till loggfilerna. En klient
kunde skicka `?mac=aa%0A[2025-01-01 00:00:00] [ERROR] fejkad rad` och skriva
godtyckliga loggposter. `logMessage()` kollapsar nu nyrader (verifierat).

### 2.7 Kommandoinjektion / process-hygien

- `getClientMacAddress()` gjorde `shell_exec("arp -n $remoteAddr")` med
  `$_SERVER['REMOTE_ADDR']` — normalt säkert, men onödigt. Ersatt med direkt
  läsning av `/proc/net/arp`: ingen process per anrop, ingen shell-yta.
- `generateEsxiPasswordHash()` använde `str_shuffle()` (inte kryptografiskt)
  för saltet, och bara 8 tecken. Nu `random_int()` och 16 tecken.

### 2.8 Läckta hemligheter

Committade i klartext: `config/credentials.json`, `config/global_config.json`
(iLO-lösenord `password`, ESXi-lösenord `VMware1!`), `config/auth_config.php`
**och** en kopia i `www/auth_config.php` (alltså i webroot) med bcrypt-hashar
för de kända lösenorden `password` / `operator`.

Åtgärd: alla fyra konverterade till `*.example.*` med platshållare, live-filerna
i `.gitignore`, `www/auth_config.php` borttagen. `nginx.conf` blockerar dessutu
`.json`, `.bak`, `.log`, `.py` under `/admin/`.

> **Viktigt:** filerna ligger kvar i git-historiken. Rotera alla iLO- och
> ESXi-lösenord, och överväg `git filter-repo` om repot delas.

### 2.9 nginx

- `autoindex on` på `/esxi/` — märkt "Temporarily enable for debugging". Gav
  full kataloglistning av installationsmedia. Avstängd.
- `.php_bak`-filer (`www/boot.ipxe.php_bak`, `lib/utils.php_bak`) serverades som
  **råtext** → källkodsläckage. Filerna borttagna + blockerade i nginx.
- Ingen HTTPS. Inloggningsformuläret och sessionscookien gick i klartext.
  Admin ligger nu på 443 med HSTS + CSP; port 80 behåller bara bootartefakterna
  (UEFI HTTP Boot och iPXE har ingen användbar trust store) och redirectar
  `/admin/` till HTTPS.
- `get_log.php` gated på `Referer`-headern (som klienten själv sätter) *före*
  sessionskontrollen. Nu är autentisering det enda som gäller.

---

## 3. Datakorruption och race conditions

`hosts.json` lästes, modifierades och skrevs osynkroniserat från **sex** olika
ställen (`boot.ipxe.php`, `generate_kickstart.php`, `deployment_complete.php`,
`host_functions.php`, `ilo_scanner.py`, `secure_boot_manager.py`).

Två konkreta fel:

1. **Förlorade uppdateringar.** Två servrar som PXE-bootar samtidigt läser båda
   filen, ändrar var sin post och skriver tillbaka — den ena ändringen försvinner.
2. **Trunkerad fil.** `file_put_contents()` skriver på plats. En krasch mitt i
   skrivningen, eller en samtidig läsare, ger en halv JSON-fil — och då är hela
   hostinventariet borta.

Åtgärd: `updateJsonConfig()` gör läs-ändra-skriv under `flock(LOCK_EX)`, och
`saveJsonConfig()` skriver till en temporärfil i samma katalog och `rename()`:ar
över målet (atomärt på POSIX). Python-skripten gör samma sak via `os.replace()`.

Verifierat: 40 parallella statusuppdateringar → 40 lyckade, 0 förlorade.
12 parallella autoregistreringar av samma MAC → exakt 1 post.

**Övrigt kring dataintegritet:**

- `formatMac()` fanns i **fyra** kopior med olika beteende. `lib/utils.php` och
  `utility_functions.php` strippade bara `:` och `-`; `boot.ipxe.php` strippade
  allt icke-hex. `00:11:22.33.44.55` normaliserades alltså olika beroende på
  endpoint, och `zz:zz:zz:zz:zz:zz` returnerades oförändrat som "giltig" MAC.
  Nu en implementation som validerar och returnerar `''` vid ogiltig indata.
- `processAddHostAction()` **skrev över** hela hostposten vid redigering. Fält
  som formuläret inte exponerar — `serial_number`, `additional_macs`, `model`,
  `bios_version` från iLO-scanningen, `deployment_started` — försvann varje
  gång någon klickade Spara. Nu `array_merge` mot befintlig post.
- `ilo_scanner.py` skrev över `mac_address` på befintliga hostar vid varje
  scanning, matchat enbart på serienummer. En godkänd host kunde tyst peka om
  till ett annat NIC. Nu uppdateras bara hårdvaruupptäckta fält.
- Tomma lösenordsfält tolkades som "radera lösenordet". Webbläsare fyller aldrig
  i lösenordsfält igen, så varje sparning i Settings nollade iLO-lösenordet.
  Nu betyder tomt fält "behåll".
- `findHostByMac()` matchar nu även `additional_macs`, så en server som bootar
  på sitt andra NIC hittas.

---

## 4. Bootkedjan

Kedjan såg ut så här — och var bruten på flera ställen:

```
DHCP ──filename──> ipxe.efi ──> ipxe/boot.ipxe ──> boot.ipxe.php ──> ??? ──> ks.cfg
```

### Fel som fanns

**`boot.ipxe.php` genererade död kod.** Skriptet skrev:

```
chain http://$webServerIP/ipxe/esxi.ipxe     <-- överlämnar kontrollen HÄR
kernel .../b.b00 ks=...                       <-- körs aldrig
module .../jumpstrt.gz                        <-- körs aldrig
boot                                          <-- körs aldrig
```

`chain` *ersätter* det körande skriptet. All den dynamiska logiken — rätt
ESXi-version per host, rätt modullista ur `boot.cfg`, rätt `ks=`-URL — kastades
alltså bort, och alla servrar hamnade i den statiska `esxi.ipxe`.

**`ipxe/esxi.ipxe` var hårdkodad** till `10.1.40.151`, version `8.0U3` och pekade
dessutom `ks=` mot `/admin/generate_kickstart.php` medan nginx exponerade
`/ks.cfg`. Den laddade `bootx64.efi` som kernel utan en enda `module`-rad — ESXi
kan inte boota utan sina moduler.

**`ipxe/boot.ipxe` använde `${dhcp-server}`** för att bygga URL:en. Med en
DHCP-relay är det relayens adress, inte webbservern. `${next-server}` är rätt.

**`boot.cfg`-filerna:**
- `esxi/boot.cfg`: `http//:10.1.40.151` — kolon och snedstreck omkastade i
  **båda** URL:erna. Ingenting kunde hämtas.
- `esxi/boot.cfg`: nyckeln hette `kernelopts=` men både ESXi och parsern i
  `boot.ipxe.php` läser `kernelopt=`. Raden ignorerades tyst.
- `boot.cfg` i roten: `prefix` pekade på `/esxi/8U3` men katalogen och
  konfigurationen heter `8.0U3`.

**Statusmaskinen.** En host i status `deploying` som bootade om fastnade i
väntloopen (bara `approved` släpptes igenom), och en `deployed` host
ominstallerades vid varje omstart eftersom ingen gren tog hand om det fallet.

**`{{IF}}`-hantering.** `processConditionals()` körde enkel `IF` **före**
`IF/ELSE`, så else-grenen alltid skrevs ut oavsett villkor.

### Kedjan efter rättning

```
DHCP  ─ next-server + filename ─────────────────────────►  ipxe.efi
  │                                                            │
  │  (redan iPXE? → boot.ipxe direkt)                          ▼
  └──────────────────────────────────────────►  ipxe/boot.ipxe
                                                               │  ${next-server}
                                                               ▼
                                        boot.ipxe.php?mac=..&serial=..
                                                               │
                    ┌──────────────────────────────────────────┤
                    │ okänd MAC      → autoregistrera → vänta   │
                    │ pending        → vänta + retry            │
                    │ deployed       → boota lokal disk         │
                    │ approved/deploying                        │
                    └──────────────────────────────────────────┘
                                                               │ läser esxi/<ver>/boot.cfg
                                                               ▼
                                   kernel <url>/b.b00 runweasel ks=<url>/ks.cfg?mac=..
                                   module <url>/jumpstrt.gz ... (~110 moduler)
                                   boot
                                                               │
                                                               ▼
                                        /ks.cfg → generate_kickstart.php
                                                               │
                                                               ▼
                                  %firstboot → deployment_complete.php?mac=..
```

Verifierat lokalt för approved host, sekundär MAC, pending host och ogiltig MAC.

---

## 5. DHCP: ISC dhcpd → Kea

### Kort svar: ja, byt till Kea.

ISC dhcpd är **end-of-life sedan december 2022** — inga säkerhetsuppdateringar,
inga buggfixar. ISC pekar själva på Kea som ersättare. Redan det motiverar bytet.

### Vad det ger den här lösningen konkret

Nuvarande flöde när någon ändrar DHCP-räckvidd i UI:t:

```
settings.php → sudo update_dhcp_config.sh
             → regenerera HELA /etc/dhcp/dhcpd.conf från en heredoc
             → dhcpd -t
             → systemctl restart isc-dhcp-server     ← DHCP nere i ~1 s
```

Det är fragilt: allt som inte finns i heredoc-mallen försvinner vid varje
sparning, och en omstart av tjänsten mitt i en deployment-våg kan tappa
DISCOVER-paket.

Med Kea:

| | ISC dhcpd | Kea |
|---|---|---|
| API | inget | `control-socket` (unix) + `kea-ctrl-agent` (REST/JSON) |
| Ändra pool | skriv om fil + restart | `config-set` / `subnet4-update` — ingen restart |
| Reservationer | omskrivning av filen | `reservation-add` per MAC, direkt |
| Leases | läs `dhcpd.leases` manuellt | `lease4-get-all` som JSON |
| Konfigformat | egen DSL | JSON — samma parser som resten av projektet |
| Backend | endast fil | memfile, MySQL, PostgreSQL |
| Support | EOL 2022 | aktivt underhållen |

Det viktiga för *just* det här projektet: **`reservation-add`**. Idag lever
IP-tilldelning i `hosts.json` och DHCP-poolen vet inget om den. Med Kea kan
`processApproveHostAction()` skicka en reservation samtidigt som hosten godkänns,
så servern får sin management-IP redan under installationen istället för en
slumpmässig pool-adress.

### Vad som är gjort

- `dhcp/kea-dhcp4.conf` — komplett Kea-konfiguration med korrekt
  klassificering (iPXE före UEFI-klasserna, annars loopar en maskin som redan
  kört iPXE på att få loadern igen).
- `update_dhcp_config.sh` stödjer båda: `DHCP_BACKEND=kea` skriver Kea-JSON,
  default (`isc`) skriver dhcpd-syntax. Båda valideras (`kea-dhcp4 -t` /
  `dhcpd -t`) innan installation, och rullas tillbaka om omstarten misslyckas.
- Skriptet i övrigt: `set -euo pipefail`, oktettvalidering (den gamla regexen
  accepterade `999.999.999.999`), validering av **alla** DNS-servrar (skrevs
  tidigare rakt in i konfigurationen ovaliderade), kontroll att poolen ryms i
  subnätet och att gateway ligger i det, atomär installation, rotation av
  backupfiler. Testat mot 5 felaktiga indata — alla avvisas före omstart.

### Rekommenderad väg framåt

1. Kör `DHCP_BACKEND=kea` med filgenerering (fungerar idag).
2. Aktivera `kea-ctrl-agent` och ersätt filgenerering + restart med `config-set`
   över control-socket.
3. Låt godkännandeflödet skapa reservationer via `reservation-add`.

Steg 2–3 är inte gjorda — se [Kvar att göra](#9-kvar-att-göra).

---

## 6. iPXE eller ren HTTP/HTTPS?

Frågan är egentligen tre olika frågor. Kort svar: **behåll iPXE, men bara som
ett tunt lager — och lägg inte tid på HTTPS i bootkedjan.**

### 6.1 Kan man hoppa över iPXE helt?

I princip ja. Modern UEFI-firmware har **HTTP Boot** inbyggt (RFC 3925/5970):
DHCP svarar med `HTTPClient` i option 60 och en `http://`-URL i `filename`, och
firmware hämtar loadern direkt över HTTP. Ingen iPXE, ingen TFTP.

Problemet: ESXi bootar inte med *en* fil. `boot.cfg` listar **~110 moduler** som
alla måste laddas innan kärnan startar. UEFI HTTP Boot hämtar exakt en fil.
Man skulle behöva peka den direkt på `mboot.efi`, som sedan själv hämtar
`boot.cfg` och modulerna relativt sin egen URL — det fungerar, men då förlorar
man den per-host-logik som är hela poängen med projektet:

- vilken ESXi-version just den här servern ska ha
- är servern godkänd, eller ska den vänta?
- retry-loopen medan den väntar på godkännande
- statusövergången `approved → deploying`

Allt det ligger i `boot.ipxe.php`, och det är iPXE:s `chain` som gör det möjligt
att fatta beslutet vid boot-tid. Firmware-HTTP-Boot kan inte förgrena sig.

**Slutsats:** iPXE gör en sak som inget annat gör här — den ger en
skriptbar beslutspunkt mellan DHCP och installeraren. Behåll den.

### 6.2 Hur ska iPXE laddas — TFTP eller HTTP?

Här är svaret entydigt: **HTTP**. Tre vägar in, i prioritetsordning:

1. **UEFI HTTP Boot** → `http://<server>/ipxe/ipxe.efi`. Ingen TFTP alls.
   Detta är default-grenen i den nya konfigurationen.
2. **Redan iPXE** (firmware med inbyggd iPXE, eller andra varvet) →
   `http://<server>/ipxe/boot.ipxe` direkt.
3. **UEFI PXE** (arch 7/9/11) → `ipxe.efi` över TFTP. Enda grenen som behöver
   en tftpd, och bara för hårdvara som inte klarar HTTP Boot. Kan tas bort helt
   om alla servrar i miljön har HTTP Boot.

Vinsten är inte teoretisk: TFTP är UDP med 512-byte-block och stop-and-wait.
En ~1 MB `ipxe.efi` tar tiotals sekunder över TFTP mot bråkdelen över HTTP.
Och `mboot.efi` + 110 moduler + `imgpayld.tgz` (flera hundra MB) över TFTP är
inte praktiskt genomförbart över huvud taget.

### 6.3 HTTPS i bootkedjan?

**Nej — inte för bootartefakterna.**

- UEFI HTTP Boot kan göra HTTPS, men bara om man lägger in CA-certifikatet i
  firmware (`TlsCaCertificate`-variabeln) på varje server. Det är ett
  administrativt projekt i sig.
- Standardbyggen av `ipxe.efi` saknar TLS-stöd. Man kan bygga med
  `DOWNLOAD_PROTO_HTTPS` och baka in en CA, men certifikatet blir då fastbränt i
  binären — nytt certifikat innebär ny `ipxe.efi` på varje omstart av kedjan.
- Innehållet är ändå publikt: installationsmedia och en modullista.

Det som **måste** ha HTTPS är admingränssnittet — inloggning och sessionscookie.
Det är gjort. `nginx.conf` delar nu upp det:

| Port | Vad | Varför |
|---|---|---|
| 80 | `/ipxe/`, `/esxi/`, `/boot.ipxe.php`, `/ks.cfg`, callback | Bootklienter har ingen trust store |
| 443 | `/admin/` | Lösenord och sessioner |

`/admin/` på port 80 redirectar till HTTPS.

**En kvarstående risk att vara medveten om:** kickstart-filen innehåller
ESXi-root-lösenordets hash och hämtas över klartext-HTTP. Vem som helst på
provisioneringsnätet kan hämta `/ks.cfg?mac=<känd MAC>` och få den. Rätt
motåtgärd är nätverkssegmentering — ett dedikerat provisioneringsvLAN — inte
TLS. Ett engångstoken i `ks=`-URL:en vore ett rimligt nästa steg.

---

## 7. TFTP

Som du sa: TFTP används inte. Det fanns heller nästan ingenting kvar av det —
bara två arv från legacy-BIOS-eran:

- `dhcpd-ipxe.conf`: `filename "http://192.168.1.5/ipxe/undionly.kpxe"` — en
  BIOS-loader serverad över HTTP, vilket BIOS-PXE inte kan hämta. Filen var
  dessutom en oanvänd dubblett med helt andra adresser (192.168.1.x) än den
  riktiga konfigurationen. **Borttagen.**
- `dhcpd.conf` / `update_dhcp_config.sh`: en `ipxe.pxe`-gren för legacy BIOS.
  **Borttagen** — ESXi 8 kräver UEFI, så BIOS-grenen kan aldrig leda till en
  lyckad installation. Klienter utan matchande arch får nu inget `filename` och
  faller igenom till nästa bootenhet i stället för att fastna.

Kvar finns **en** TFTP-beroende gren: UEFI PXE (arch 7/9/11), som hämtar
`ipxe.efi` över TFTP. Den är dokumenterad som valfri i både `dhcp/dhcpd.conf`
och `dhcp/kea-dhcp4.conf` — aktivera HTTP Boot i firmware så kan du ta bort den
och avinstallera tftpd helt.

---

## 8. Kodkvalitet och prestanda

### Duplicerad kod

Nio funktioner fanns i två till fyra kopior med olika beteende:

| Funktion | Kopior | Placering före |
|---|---|---|
| `formatMac` | 4 | `lib/utils.php`, `utility_functions.php`, `boot.ipxe.php`, `version-selector.php` |
| `logMessage` | 4 | samma fyra |
| `getClientMacAddress` | 3 | `lib/utils.php`, `utility_functions.php`, `version-selector.php` |
| `loadJsonConfig` / `saveJsonConfig` | 2 | `lib/utils.php`, `config_functions.php` |
| `loadSecureCredentials` | 2 | olika signaturer (`$macAddress` saknades i den ena) |
| `findHostByMac` | 2 | `lib/utils.php`, `config_functions.php` |
| `renderTemplate` | 2 | två helt olika implementationer |
| `generateEsxiPasswordHash` | 2 | `lib/utils.php`, `hardware_functions.php` |

Det var inte bara redundans — vilken variant som gällde berodde på include-ordning,
och de betedde sig olika. `lib/utils.php` är nu enda källan (alla funktioner
`function_exists`-skyddade); `www/*_functions.php` innehåller bara det som är
unikt för admin-UI:t. Nettoresultat: ~340 rader borttagna.

### Prestanda

- `ilo_scanner.py` itererade `for future in futures` och blockerade på
  `.result()` i submitteringsordning — en långsam host stoppade alla efter den.
  Nu `as_completed()`, och trådantalet höjt 5 → 16 (I/O-bundet: ping + HTTPS).
- Samma skript anropade `load_global_config()` två gånger per hostskrivning
  (en gång i `load_hosts_config`, en i `save_hosts_config`) — filen lästes och
  JSON-parsades hundratals gånger per scanning. Nu cachad.
- **En kastande future avbröt hela scanningen** och kastade alla hittade hostar.
  Nu fångas per host.
- `renderTemplate()` gjorde `str_replace()` i en loop över alla variabler; en
  ersatt sträng kunde alltså expanderas igen av en senare variabel. Nu en enda
  `str_replace()` med arrayer — både snabbare och korrekt.
- `logMessage()` skriver med `LOCK_EX` (samtidiga skrivningar kunde interfoliera).
- `ilo_scanner.py` loggade **hela BIOS-attributuppsättningen** på `INFO` för
  varje scannad server — hundratals kB per scanning. Nu `DEBUG`.
- Scanningen avvisar räckvidder > 4096 adresser i stället för att försöka.
- `runIloScanner()` kör under `timeout` så en hängande scan inte låser
  PHP-FPM-workern för alltid.

### Robusthet

- Alla `fopen()` kontrolleras (`dashboard.php` `fseek`:ade på `false`).
- `catch (Exception)` → `catch (Throwable)`: PHP 8 kastar `Error`, inte
  `Exception`, för de vanligaste felen — de gamla blocken fångade ingenting.
- `${_SERVER['HTTP_HOST']}` i sträng — deprecerad syntax i PHP 8.2, **borttagen
  i PHP 9**. Ersatt.
- `secure_boot_manager.py` läckte Redfish-sessionen på varje felväg (iLO tillåter
  bara ett fåtal samtidiga). Nu `finally: logout()`.
- Samma skript ignorerade host-specifika iLO-uppgifter helt — servrar med eget
  iLO-konto kunde aldrig autentiseras. Nu används de.
- Skriptet accepterade bara HTTP 200 från Redfish; iLO svarar 202 beroende på
  firmwareversion. Nu 200/202/204.
- `import redfish` på modulnivå kraschade skriptet med en naken traceback om
  modulen saknades. Nu ett begripligt felmeddelande.
- `deployment_complete.php` hade hela secure-boot-återaktiveringen bortkommenterad
  med "temporarily skipping to avoid module error" — servrar rapporterades klara
  utan att secure boot slogs på igen. Återinförd med en riktig modulkontroll.

### Kickstart-mallar

- `kickstart_template_std.cfg` hade **hårdkodad vMotion-IP `192.168.10.215`** och
  använde inte de `VMOTION_*`-variabler som `generate_kickstart.php` faktiskt
  skickade in. Varje host som deployades fick alltså samma vMotion-adress.
  Nu variabeldrivet och inbäddat i ett villkorsblock.
- `kickstart_template_vcf.cfg`: raden ` Extract domain from FQDN...` saknade sitt
  `#` — busybox försökte köra den vid varje `%firstboot`.
- Samma fil: `/bin/generate-certificates` — binären ligger i `/sbin`.
- Samma fil: `while ! vim-cmd hostsvc/runtimeinfo; do sleep 10; done` — oändlig
  loop utan tak om hostd inte kommer upp. Nu max 5 minuter.
- Samma fil hämtade och körde `notify_deployment.py` med `python`, som ESXi 8
  inte längre levererar som default. Ersatt med `curl` (finns i både 7 och 8).
  `notify_deployment.py` borttagen.
- `kickstart_template.cfg` var en oanvänd dubblett av `_std`-varianten (och båda
  var felaktigt kommenterade "Kickstart template for VCF"). Borttagen.
- `waiting_template.cfg` refererades i konfigurationen men fanns inte i repot —
  `waiting_template_path` pekade i praktiken på VCF-mallen, så en host som
  väntade på godkännande hade blivit **installerad**. Mallen finns nu.

### Borttagna filer

`www/auth_config.php`, `www/simple_auth.php`, `www/boot.ipxe.php_bak`,
`lib/utils.php_bak`, `ipxe/esxi.ipxe_bak`, `www/bootstrap.min.css` (dubblett av
`www/css/bootstrap.min.css`), `www/version-selector.php` (oanvänd, ingen
nginx-route pekade på den), `www/notify_deployment.py`, `dhcpd-ipxe.conf`,
`templates/kickstart_template.cfg`.

### Hårdkodade adresser

Alla borta ur koden. Kvarvarande adresser är RFC 5737-dokumentationsadresser
(`192.0.2.0/24`, `198.51.100.0/24`) i exempel- och docfiler:

| Var | Före | Efter |
|---|---|---|
| `ipxe/boot.ipxe` | `${dhcp-server}` | `${next-server}` från DHCP |
| `ipxe/esxi.ipxe` | `set server 10.1.40.151`, `set version 8.0U3` | `${next-server}`, version från hostposten |
| `boot.cfg`, `esxi/boot.cfg` | `prefix=http://10.1.40.151/...` | relativ `prefix`; URL:er byggs av `boot.ipxe.php` |
| `templates/*.cfg` | vMotion-IP `192.168.10.215`, `generalfailure.io` | `{{VMOTION_IP}}` m.fl. |
| `config/*.json` | riktiga lab-adresser + lösenord | `*.example.json` med `CHANGEME` |
| `dhcp/*.conf` | riktiga lab-adresser | RFC 5737, genereras av update-skriptet |
| `www/templates.php` | `10.1.40.151`, `192.168.10.215` i doctabeller | RFC 5737 |
| `nginx.conf` | — | inga adresser; värdnamn från requesten |
| `scripts/*.py` | `/srv/autodeploy` hårdkodat 6 ggr per fil | `AUTODEPLOY_ROOT` (env, default oförändrad) |
| PHP-filer | `/srv/autodeploy/...` spritt överallt | `AUTODEPLOY_*`-konstanter |

---

## 9. Kvar att göra

Medvetet inte gjort i den här omgången:

1. **Rotera alla lösenord.** Hemligheterna ligger kvar i git-historiken.
   Överväg `git filter-repo`.
2. **Kea control API.** `update_dhcp_config.sh` skriver fortfarande en fil och
   startar om tjänsten även i Kea-läge. Nästa steg är `config-set` över
   control-socket, och `reservation-add` vid godkännande av en host.
3. **`getSystemInfoViaIlo()`** skickar lösenordet som kommandoradsargument —
   synligt i `ps` för alla lokala användare. Bör gå via stdin eller miljövariabel.
   Skriptet det anropar (`ilo_info.py`) finns inte i repot.
4. **Rollkontroll per åtgärd.** `hasPermission()` finns nu i `lib/auth.php` men
   används inte — en `operator` kan fortfarande göra allt en `admin` kan.
5. **Engångstoken i `ks=`-URL:en**, så root-lösenordshashen inte kan hämtas av
   vem som helst på provisioneringsnätet.
6. **Automatiska tester.** Verifieringen i den här omgången gjordes manuellt.
   Logiken i `lib/utils.php` (MAC-normalisering, sökvägsvalidering,
   mallrendering, låsning) lämpar sig väl för PHPUnit.
7. **`templates.php` är 1700 rader** — render- och åtgärdslogiken bör delas upp.
