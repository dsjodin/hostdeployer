# Plan: porta via_go:s styrkor till hostdeployer

Underlaget är en jämförelse mellan det här repot och
[`dsjodin/via_go`](https://github.com/dsjodin/via_go) — en fork av
maxiepax/go-via, som löser samma problem (automatiserad ESXi-provisionering)
med rakt motsatt arkitektur. Sju konkreta saker är värda att hämta hem.

Relaterat: [`CODE-REVIEW.md`](CODE-REVIEW.md) · [`bootchain.md`](bootchain.md)

**Status:** fas 1–5 är genomförda. Fas 6–7 återstår.

> **Avvikelse i fas 4:** bara värdinventariet flyttade till SQLite.
> `credentials.json` och `global_config.json` är kvar som filer — de är små
> nästlade dokument som operatören fyller i för hand vid installation, och en
> databas hade tagit bort det utan att ge något tillbaka. Hemligheterna i
> credentials-filen är krypterade sedan fas 3.

---

## Kontext

`hostdeployer` är en **orkestrerare**: PHP + nginx + Kea/ISC dhcpd + iPXE, med
JSON-filer som datalager. `via_go` är en **appliance**: en Go-binär som själv är
DHCP-, TFTP- och HTTP-server, med SQLite, REST-API och tester i CI.

Tre svagheter driver hela arbetet:

1. **ESXi-rootlösenordet ligger i klartext** i `config/credentials.json`, skyddat
   bara av filrättigheter — på en tjänst vars hela syfte är att dela ut det.
2. **Inga tester, ingen CI.** [`CODE-REVIEW.md §1`](CODE-REVIEW.md) dokumenterar
   tre funktioner som *anropades men aldrig definierades* (hela Hardware
   Scan-fliken död) och en dubblerad `case` i en switch. Det är precis den
   buggklass statisk analys fångar gratis.
3. **Inget API.** Allt är HTML-formulärposter, så verktyget går inte att
   automatisera — vilket är hela poängen med en VIA.

### Beslut som formar planen

- **Python-skripten går över till REST-API:t.** `ilo_scanner.py` och
  `secure_boot_manager.py` läser och skriver idag `hosts.json` och
  `credentials.json` direkt, med egna `load_hosts_config`/`save_hosts_config`.
  Både kryptering och SQLite bryter den gränsen. Därför blir **API:t en
  förutsättning för kryptering och SQLite** — ordningen på ursprungslistan
  kastas om.
- **Båda bootvägarna behålls.** UEFI HTTP Boot läggs till vid sidan av
  iPXE-kedjan, valt av DHCP-klass. Ingen hårdvara tappas.
- **Greenfield.** Ingen produktionsdata i `hosts.json` att migrera; schemat kan
  skapas rakt av och JSON-stödet för hostar tas bort direkt.

### Den bärande idén

Ett repository-lager, `lib/store.php`, införs i fas 2 med **samma
funktionssignaturer som idag**. Därefter blir kryptering (fas 3) och SQLite
(fas 4) byten av implementationen bakom det gränssnittet, och API:t och den
befintliga PHP-UI:n sitter båda ovanpå. Utan det lagret blir varje efterföljande
fas en shotgun-ändring genom hela `www/`.

---

## Fas 1 — Tester och CI ✅

Säkerhetsnätet måste finnas före refaktoreringarna, inte efter.

**Nya filer:** `composer.json` (dev-only), `phpunit.xml`, `phpstan.neon`,
`tests/`, `.github/workflows/ci.yml`

- `composer.json`: `phpunit/phpunit ^11` och `phpstan/phpstan ^2` under
  `require-dev`. Deployas aldrig — README:ns `rsync` får `--exclude vendor
  --exclude tests`.
- **Extrahera `boot.cfg`-parsningen** ur `www/boot.ipxe.php` (parse-loopen kring
  rad 258–292) till en ny `lib/bootcfg.php` som
  `parseBootCfg(string $contents): array{kernel, kernelopt, modules}`. Gör den
  testbar och är samtidigt förutsättning för fas 7.
- Testa de rena funktionerna i `lib/utils.php`, med tonvikt på de fall
  kommentarerna redan säger har gått sönder: `formatMac()` (returnerar `''` för
  skräp, inte `"zz:zz"`), `isValidNetmask()` (icke-sammanhängande mask avvisas),
  `safePathJoin()` (traversal), `renderTemplate()` + `processConditionals()`
  (`{{IF}}`/`{{ENDIF}}`), `hostMatchesMac()` (träffar `additional_macs`),
  `findHostByMac()`, `generateEsxiPasswordHash()` (verifiera med `crypt()`),
  `extractHostnameFromFQDN()`.
- Testbootstrap sätter `AUTODEPLOY_ROOT` till en temp-katalog innan
  `lib/utils.php` inkluderas — konstanterna definieras vid include (rad 14–33).
- CI-jobb: `php -l` över hela trädet, PHPUnit, PHPStan level 5, `shellcheck`
  på `update_dhcp_config.sh`, `ruff` + `py_compile` på `scripts/`.

> PHPStan level 5 fångar exakt "Call to undefined function"-buggarna i
> CODE-REVIEW.md §1. Det är enda anledningen som behövs för det här jobbet.

**Blockerare att lösa här:** `lib/auth.php:41` anropar `startAdminSession()` vid
include, vilket skickar headers och gör filen omöjlig att inkludera i ett test
eller från API:t. Gör anropet lazy och flytta det till de sidor som behöver det
(`www/admin_dashboard.php`, `www/login.php`, `www/logout.php`, `www/get_log.php`).

---

## Fas 2 — `lib/store.php` + REST-API ✅

**Nya filer:** `lib/store.php`, `lib/api_auth.php`, `www/api.php`

### Repository-lagret

`lib/store.php` samlar all host- och credential-åtkomst. Första versionen
delegerar till befintliga `loadJsonConfig`/`updateJsonConfig` — ingen
beteendeändring, bara en fasad:

```
loadHosts()                     findHostByMac($mac)
addHost(array $host)            updateHostByMac($mac, array $data)
deleteHost($mac)                setHostStatus($mac, $status)
loadCredentials($type, $mac)    saveCredentials(array $creds)
```

Flytta hit motsvarande funktioner från `lib/utils.php` (rad 536–648) och låt de
gamla namnen vara tunna wrappers, så inget i `www/` behöver röras ännu.

### API:t

`www/api.php` som front controller, routad av nginx. Endpoints:

| Metod | Väg | Not |
|---|---|---|
| GET/POST | `/api/v1/hosts` | lista / skapa |
| GET/PATCH/DELETE | `/api/v1/hosts/{mac}` | |
| POST | `/api/v1/hosts/{mac}/approve` | |
| POST | `/api/v1/hosts/{mac}/reinstall` | |
| GET | `/api/v1/hosts/{mac}/status` | för fas 5 |
| GET/PUT | `/api/v1/credentials/{type}` | för Python-skripten |
| POST | `/api/v1/scan` | trigga iLO-skanning |
| GET | `/api/v1/versions` | tillgängliga ESXi-images |

- **Återanvänd `validateHostNetworkInput()`** (`www/host_functions.php:71`) —
  den validerar redan IP, netmask, gateway, VLAN och vMotion. Filen har ingen
  `ADMIN_DASHBOARD`-guard, så den går att inkludera direkt.
- **Auth:** bearer-token, hashade tokens i `config/auth_config.php` under
  `api_tokens`, jämförda med `hash_equals()`. Återanvänd `hasPermission()`
  (`lib/auth.php:290`) för rollmappningen. API:t startar aldrig en session.
- **nginx:** `location /api/` **bara i 443-blocket**. Automation ska inte gå i
  klartext, och port 80 är reserverad för bootkedjan.

### Porta Python-skripten

Ersätt `load_hosts_config`/`save_hosts_config`/`load_credentials` i
`scripts/ilo_scanner.py` (rad 89–135) och `scripts/secure_boot_manager.py`
(rad 85–125) med `requests`-anrop mot API:t. `requests` är redan ett beroende.
Token från `AUTODEPLOY_API_TOKEN`. Efter det här läser inget utanför PHP
konfigurationsfilerna direkt — vilket är det som gör fas 3 och 4 möjliga.

---

## Fas 3 — Kryptera credentials at rest ✅

**Ny fil:** `lib/secrets.php`

```
secretsKey(): string          // ladda-eller-skapa config/secret.key, 0600
secretEncrypt(string): string
secretDecrypt(string): string
```

- **Använd `sodium_crypto_aead_xchacha20poly1305_ietf_*`, inte AES-GCM.**
  Sodiums AES-256-GCM kräver `sodium_crypto_aead_aes256gcm_is_available()`
  (AES-NI i hårdvara), medan XChaCha20-Poly1305 alltid finns med inbyggd
  libsodium och har 24-byte nonce så slumpade nonces är säkra utan räknare.
- Format `v1.` + base64(nonce‖ciphertext), så nyckelrotation är möjlig senare.
- Nyckelskrivning: `tempnam()` + `chmod 0600` + `rename()` — samma atomiska
  mönster som `saveJsonConfig()` redan använder (`lib/utils.php:356–394`), och
  av samma skäl som via_go:s `internal/secrets` anger: en avbruten skrivning får
  inte lämna en trunkerad nyckel som gör varje lagrat lösenord oläsbart.
- `sodium_memzero()` på klartextbuffertar efter användning.

**Kryptera bara hemligheterna**, inte hela filen:
`ilo.admin_password`, `ilo.hosts.*.password`, `esxi.root_password`,
`esxi.hosts.*.root_password`.

Kryptering och dekryptering sker **inuti `lib/store.php`:s
`loadCredentials`/`saveCredentials`**, så ingen anropare ändras:
`www/generate_kickstart.php:142`, `www/host_functions.php:21,65,299,302`,
`www/settings.php:19,984,1015`. Python är redan frikopplat efter fas 2.

**Dokumentation:** README:ns installationssteg 2 + samma varning som via_go:s
README bär — *förlorar du `config/secret.key` blir varje lagrat lösenord
oåterkalleligt oläsbart*. Lägg `secret.key` i `.gitignore`.

---

## Fas 4 — SQLite via PDO ✅

**Ny fil:** `lib/db.php`. Greenfield, så schemat skapas rakt av.

- PDO mot `config/autodeploy.db`, med `PRAGMA journal_mode=WAL`,
  `foreign_keys=ON`, `busy_timeout=5000`.
- Tabeller: `hosts` (mac PK, hostname, fqdn, esxi_version, management_*,
  vmotion_*, vlan_*, deployment_type, deployment_status, secure_boot_status,
  serial_number, ilo_ip, datastore_name, progress, progress_text, tidsstämplar)
  och **`host_macs`** (mac PK, host_mac FK) som ersätter `additional_macs`-arrayen.
- Skriv om kropparna i `lib/store.php` mot PDO. Gränssnittet är oförändrat, så
  `www/` följer med utan ändringar utöver de fyra `updateJsonConfig`-anropen i
  `www/host_functions.php` (172, 276, 385, 463) och läsningarna i
  `www/boot.ipxe.php` (79, 124, 153), `www/generate_kickstart.php:53`,
  `www/admin_dashboard.php:144`.
- Transaktioner ersätter read-modify-write av hela filen. **Auto-registrerings-
  racet** i `www/boot.ipxe.php:124` — två NIC:ar på samma server som träffar
  endpointen samtidigt — blir `INSERT ... ON CONFLICT(mac) DO NOTHING` istället
  för lås-och-kontrollera-igen.
- `host_macs` gör `hostMatchesMac()`:s linjära scan (`lib/utils.php:578`) till
  en indexerad uppslagning. Bootvägen scannar idag varje host vid varje request.
- `loadJsonConfig`/`saveJsonConfig`/`updateJsonConfig` behålls — men bara för
  `global_config.json`, som förblir en fil.

---

## Fas 5 — Progressrapportering ✅

Kolumnerna `progress` och `progress_text` finns redan i fas 4-schemat.

Checkpoints, med via_go:s procentsatser som förlaga
(`internal/server/uefi.go`: mboot 10, crypto64 12, boot.cfg 15, ks 50):

| % | Var |
|---|---|
| 10 | `www/boot.ipxe.php` när bootskriptet genererats |
| 15 | `www/boot.cfg.php` (fas 7) |
| 50 | `www/generate_kickstart.php` |
| 75 | `%firstboot`-beacon i båda kickstart-mallarna |
| 100 | `www/deployment_complete.php` (finns redan) |

`templates/kickstart_template_std.cfg` och `_vcf.cfg` har redan
`/bin/curl`-callbacken i `%firstboot` att bygga vidare på.

**Visning:** dashboarden pollar `GET /api/v1/hosts/{mac}/status` var 3:e sekund.
Rekommenderat framför SSE — PHP-FPM binder en worker per öppen SSE-anslutning,
vilket blir en denial-of-service mot en själv vid 20 samtidiga installationer.
Progressbaren i `www/hosts.php` använder Bootstrap `progress`, som redan finns
i `www/css/bootstrap.min.css`.

---

## Fas 6 — ISO-uppladdning med hashverifiering

- `POST /api/v1/images` (multipart) + uppladdningsformulär i `www/settings.php`.
- Verifiera sha256 mot medskickad hash med `hash_file('sha256', $path)` —
  motsvarar strömmande `io.Copy(h, f)` i via_go:s `internal/api/image.go`.
  Vid felaktig hash: radera och returnera 400.
- **Uppackning:** ingen PHP-ISO-läsare utan nytt beroende, så
  `bsdtar -xf <iso> -C <dir>` (libarchive, klarar ISO9660 och UDF) via
  `escapeshellarg()`. Målkatalogen valideras med befintliga `safePathJoin()`
  (`lib/utils.php:250`).
- Versionsnamnet valideras med samma `^[A-Za-z0-9._-]+$` som
  `www/boot.ipxe.php:241` redan kräver, eftersom det blir både sökväg och URL.
- **Efterkontroll:** `boot.cfg` måste finnas i den uppackade katalogen, annars
  avvisa och städa. Det är exakt felet [`bootchain.md`](bootchain.md) listar som
  "Installer startar men hittar inga moduler".
- **Obs:** `client_max_body_size` är `2m` i båda serverblocken i `nginx.conf`.
  Höj i 443-blocket (ISO:er är flera GB) tillsammans med `upload_max_filesize`
  och `post_max_size` i php.ini. Port 80 lämnas på 2m — installers POSTar inte.

---

## Fas 7 — Omskriven `boot.cfg` + UEFI HTTP Boot vid sidan av iPXE

Störst förändring, och den som gör bootkedjan robust över ESXi-versioner.

- Utöka `lib/bootcfg.php` (från fas 1) med `renderBootCfg()`, portad från via_go:s
  `internal/boot/bootcfg.go`: strippa `/`-separatorer, strippa `cdromBoot`,
  appendera till `kernelopt=` (`ks=`, `netdevice=`, `ip=`, `netmask=`,
  `gateway=`, `vlanid=`, `allowLegacyCPU=true`) och sätt `prefix=`.
  Ordningen spelar roll: separatorerna måste bort innan URL:er läggs till,
  annars strippas snedstrecken i URL:erna också.
- Ny `www/boot.cfg.php?mac=` serverar den per host. Servera `mboot.efi` från
  `esxi/<ver>/efi/boot/` på en stabil URL.
- **DHCP** (`dhcp/kea-dhcp4.conf` + `update_dhcp_config.sh`): behåll klassen
  `PXEClient:Arch:00007` → iPXE, lägg till `HTTPClient:Arch:00016` →
  `http://<server>/boot/<mac>/mboot.efi`. UEFI HTTP Boot kräver att servern
  ekar tillbaka option 60 = `HTTPClient` i svaret.
- **iPXE-vägen byter också** till att `chain`:a mboot med samma genererade
  `boot.cfg`, istället för att emittera ~110 `module`-rader. En
  omskrivningsrutin, två transporter — vilket är precis den drift-bugg som
  via_go:s `internal/boot`-paket beskriver att man annars får (samma
  omskrivning fanns där i två kopior som hunnit driva isär).
- Testa **båda** vägarna på nested ESXi-VM:ar innan hårdvara rörs.

---

## Verifiering

| Fas | Kommando / kontroll |
|---|---|
| 1 | `composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse` — grönt, och CI-workflowen grön på en push |
| 2 | `curl -H "Authorization: Bearer $TOKEN" https://<server>/api/v1/hosts`; `python3 scripts/ilo_scanner.py --dry-run` hittar samma hostar som före; `curl` utan token ger 401; API:t svarar inte på port 80 |
| 3 | `config/credentials.json` innehåller bara ciphertext; `generate_kickstart.php` producerar fortfarande en giltig `$6$`-hash; borttagen `secret.key` ger tydligt fel, inte tyst tomt lösenord |
| 4 | `sqlite3 config/autodeploy.db .schema`; två parallella `curl` mot samma nya MAC skapar exakt en rad; hela UI-flödet add → approve → reinstall → delete |
| 5 | Följ en nested-installation och se 10 → 50 → 75 → 100 i UI:t |
| 6 | Ladda upp en ESXi-ISO, verifiera att fel hash avvisas, att `boot.cfg` finns efter uppackning, och att versionen dyker upp i `/api/v1/versions` |
| 7 | En nested VM per bootväg: en med PXE-klass, en med HTTP Boot-klass. Båda ska nå `deployed` |

**End-to-end efter varje fas:** en nested ESXi-VM bootar, registreras som
`pending`, godkänns i UI:t, installeras och rapporterar `deployed`. Det flödet
rör bootkedjan, kickstart-generatorn, lagret och statusmaskinen samtidigt och är
den enda kontroll som säger att en refaktorering inte tappat något.

**Rekommenderad leveransordning:** fas 1 och 2 är den halva som ger mest och är
oberoende av resten — de kan levereras och användas innan 3–7 påbörjas.
