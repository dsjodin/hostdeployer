# Säkerhetsgranskning — hostdeployer, juli 2026

Granskning av hela trädet: PHP under `lib/` och `www/`, `nginx.conf`,
`install.sh`, `deploy/kea-config.sh`, Python-hjälparna och kickstart-mallarna.

Fortsättning på [`CODE-REVIEW.md`](CODE-REVIEW.md), som gjorde den förra
omgången. Två av punkterna i dess "Kvar att göra" — rollkontroll per åtgärd och
engångstoken i `ks=`-URL:en — är fortfarande öppna och står som **S1** och **S3**
nedan, nu med en konkret design.

Sorterat efter allvarlighetsgrad. Varje fynd har en föreslagen åtgärd.

| # | Fynd | Grad | Status |
|---|---|---|---|
| [S1](#s1) | `/ks.cfg` lämnar ut ESXi-rootlösenordets hash oautentiserat | **Kritisk** | **åtgärdad** |
| [S2](#s2) | Admin-UI:t gör ingen behörighetskontroll alls | **Kritisk** | **åtgärdad** |
| [S3](#s3) | Oautentiserade tillståndsändringar i bootkedjan | **Hög** | **åtgärdad** |
| [S4](#s4) | `deployment_complete.php` sover 10 s per request → DoS | **Hög** | öppen |
| [S5](#s5) | Loginformuläret verifierar aldrig CSRF-token | **Hög** | **åtgärdad** |
| [S6](#s6) | Brute force-skyddet ligger i sessionen och kringgås trivialt | **Hög** | **åtgärdad** |
| [S7](#s7) | Admin-UI och API exponeras på provisioneringsnätet | **Medel** | öppen |
| [S8](#s8) | `local-helpers`-token har admin-roll och ligger läsbar för www-data | **Medel** | öppen |
| [S9](#s9) | Hjälpar-token passerar genom ett shell-kommando | **Medel** | öppen |
| [S10](#s10) | `is_uploaded_file()` saknas i ISO-uppladdningen | **Medel** | **åtgärdad** |
| [S11](#s11) | ISO-extrahering av opålitligt arkiv utan sandlåda | **Medel** | öppen |
| [S12](#s12) | Klartextlösenord kvar som fallback i `global_config.json` | **Medel** | öppen |
| [S13](#s13) | `get_log.php` saknar behörighetskontroll | **Låg** | **åtgärdad** |
| [S14](#s14) | CSP tillåter `unsafe-inline` för script | **Låg** | öppen |
| [S15](#s15) | `alias` med regex-capture på `/esxi/` | **Låg** | öppen |
| [S16](#s16) | Obegränsad e-post vid autoregistrering | **Låg** | öppen |
| [S17](#s17) | Ingen absolut sessionslivslängd | **Låg** | öppen |
| [S18](#s18) | Timing-utjämningen i `verifyCredentials()` är sprö | **Info** | öppen |

> ### Migrering — läs innan uppgradering
>
> **Efter S1/S3.** Bootkedjan kräver nu en token. Två saker slutar annars
> fungera tyst:
>
> 1. **Egna och redigerade kickstart-mallar.** De levererade mallarna har fått
>    `&t={{BOOT_TOKEN}}` på anropen till `progress.php` och
>    `deployment_complete.php`. En mall som redigerats eller laddats upp genom
>    admin-UI:t har det inte, och `install.sh` rör aldrig en befintlig mall — så
>    dess hostar installeras klart och fastnar sedan på "deploying", eftersom
>    slutcallbacken avvisas med 403.
>
>    ```bash
>    cd /srv/autodeploy/templates
>    sed -i 's#\(progress\.php?mac={{MAC_ADDRESS}}\)#\1\&t={{BOOT_TOKEN}}#;
>            s#\(deployment_complete\.php?mac={{MAC_ADDRESS}}\)#\1\&t={{BOOT_TOKEN}}#' *.cfg
>    grep -n 'BOOT_TOKEN' *.cfg      # varje curl-rad ska ha den
>    ```
>
> 2. **Installationer som pågår vid uppgraderingen.** Deras kickstart hämtades
>    innan tokens fanns. Boota om dem så går de genom bootkedjan igen och får en
>    token.
>
> **Efter S2.** Rolltabellen har fått behörigheten `templates`. `install.sh`
> skriver den för nya installationer men rör aldrig en befintlig
> `auth_config.php`. En `admin` påverkas inte — `roleHasPermission()` svarar ja
> på allt för den rollen — men ska någon annan roll få redigera kickstart-mallar
> behöver `'templates'` läggas till i dess `permissions` för hand.

---

<a id="s1"></a>
## S1. `/ks.cfg` lämnar ut ESXi-rootlösenordets hash oautentiserat — **kritisk**

`www/generate_kickstart.php:60,157`

```php
$clientMac = formatMac($_GET['mac'] ?? '');
...
'ROOT_PASSWORD_HASH' => generateEsxiPasswordHash($rootPassword),
```

Vem som helst som kan nå port 80 och känner till — eller gissar — en MAC-adress
i status `approved` eller `deploying` får tillbaka en fullständig kickstart:

* `$6$…` — SHA-512 crypt av det rootlösenord **hela estatet** installeras med
* management-IP, netmask, gateway, VLAN
* DNS- och NTP-servrar, FQDN, datastore-namn

MAC-adresser är inte hemligheter. De ligger i ARP-tabeller, i switchens
MAC-tabell, i varje broadcast på segmentet, och adressrummet inom en känd OUI är
litet nog att räkna igenom. En angripare på provisioneringsnätet — eller vem som
helst om port 80 exponeras bredare, se [S7](#s7) — får en hash att köra offline
mot, och därmed root på varje utrullad host.

Endpointen *kan* inte autentiseras på vanligt sätt: klienten är weasel, en
installer utan credentials. Men den kan kräva en hemlighet servern själv delade
ut ett steg tidigare i kedjan.

### Åtgärd: engångstoken i `ks=`-URL:en

Kedjan har redan ett läge där servern skriver en URL som bara den här hosten får:
`boot.cfg.php` och `boot.ipxe.php` bygger `ks=`-parametern. Låt dem lägga en
token i den.

1. Två kolumner i `hosts` (`lib/db.php:84`):
   ```sql
   boot_token         TEXT,
   boot_token_expires TEXT
   ```
2. `lib/store.php` får `storeIssueBootToken($mac)` — `bin2hex(random_bytes(32))`,
   giltig 2 h — och `storeConsumeBootToken($mac, $token)` som jämför med
   `hash_equals()`, kontrollerar utgången och nollar kolumnen.
3. `www/boot.cfg.php:115` och `www/boot.ipxe.php:279` byter
   `'/ks.cfg?mac=' . $mac` mot `'/ks.cfg?mac=' . $mac . '&t=' . storeIssueBootToken($mac)`.
4. `www/generate_kickstart.php` kräver `$_GET['t']` och avbryter med `ksAbort()`
   när den saknas, gått ut eller inte stämmer.
5. Tokenen nollas när kickstarten hämtats. En ominstallation går genom
   bootkedjan igen och får en ny.

Kompletterande lager, oberoende av ovanstående:

* Bind port 80 till provisioneringsbenet — se
  [`network-segmentation.md`](network-segmentation.md).
* Kontrollera att `$_SERVER['REMOTE_ADDR']` har en aktiv Kea-lease för den MAC
  som efterfrågas. `lib/kea.php` kan redan tala med kontrollsocketen;
  `lease4-get` tar `hw-address`. Stoppar en angripare på samma segment som
  gissar MAC:ar från en annan adress.
* Rotera ESXi-rootlösenordet efter installation, per host. Ligger utanför den
  här granskningen men är det som gör en läckt hash ointressant.

---

<a id="s2"></a>
## S2. Admin-UI:t gör ingen behörighetskontroll alls — **kritisk**

`www/admin_dashboard.php:88-139`

`hasPermission()` finns i `lib/auth.php:330` och används på **ett** ställe i hela
trädet: `www/host_status.php:34`. Dashboardens POST-router kontrollerar CSRF och
dispatchar sedan direkt:

```php
if (!verifyCsrfToken($_POST)) { ... } else {
    switch ($activeTab) {
        case 'hosts':     $result = processHostsActions($action, $_POST);     break;
        case 'settings':  $result = processSettingsActions($action, $_POST);  break;
        case 'templates': $result = processTemplatesActions($action, $_POST, $_FILES); break;
        ...
```

Ingen av handlarna kontrollerar rollen. Följden är att den `operator` som
`auth_config.example.php:29` definierar med `['read', 'approve', 'scan']` i
praktiken kan göra allt en `admin` kan:

| Åtgärd | Vad det ger | Krävd behörighet idag |
|---|---|---|
| `save_template` / `upload_template` | Kickstart-mallar körs som **root** i `%firstboot` på varje utrullad host → **RCE på hela estatet** | ingen |
| `save_default_credentials` | skriva iLO- och ESXi-rootlösenord | ingen |
| `save_network_config` | skriva om DHCP via Keas kontroll-API | ingen |
| `delete_host`, `delete_template` | ta bort inventarium och mallar | ingen |
| ESXi-image upload/delete | vad hostarna installerar | ingen |

REST-API:t gör det här korrekt — `apiRequire()` (`www/api.php:119`) framför varje
handler. Samma roll ger alltså helt olika rättigheter beroende på om man kommer in
med cookie eller med bearer-token. Behörighetsmodellen finns; UI:t använder den
bara inte.

### Åtgärd: en behörighetstabell framför dispatchern

En tabell, kontrollerad före `switch`:en, är hela fixen:

```php
/**
 * Vilken behörighet varje formuläråtgärd kräver.
 *
 * Åtgärder som inte står här avvisas. En ny åtgärd som glöms bort blir
 * därmed otillgänglig i stället för öppen för alla.
 */
function actionPermission($action) {
    static $map = [
        'logout'                      => null,        // alltid tillåten
        'add_host'                    => 'write',
        'delete_host'                 => 'write',
        'approve_host'                => 'approve',
        'reinstall_host'              => 'approve',
        'toggle_secure_boot'          => 'write',
        'scan_ilo'                    => 'scan',
        'save_global_config'          => 'settings',
        'save_default_credentials'    => 'settings',
        'save_auto_registration'      => 'settings',
        'save_security_settings'      => 'settings',
        'save_network_config'         => 'settings',
        'upload_image'                => 'settings',
        'delete_image'                => 'settings',
        // Mallar körs som root på varje host. Egen behörighet, inte 'settings'.
        'save_template'               => 'templates',
        'create_template'             => 'templates',
        'upload_template'             => 'templates',
        'delete_template'             => 'templates',
        'restore_backup'              => 'templates',
        'delete_backup'               => 'templates',
        'backup_template'             => 'templates',
        'download_template'           => 'templates',
        'update_template_assignments' => 'templates',
    ];

    return array_key_exists($action, $map) ? $map[$action] : false;
}
```

och i routern:

```php
$permission = actionPermission($action);

if ($permission === false) {
    dashboard_log("Rejected unknown action '$action'", 'WARNING');
    $error = 'Unknown action.';
} elseif ($permission !== null && !hasPermission($permission)) {
    dashboard_log("User {$authenticated['username']} denied '$action' (needs $permission)", 'WARNING');
    $error = "Your account does not have permission to do that.";
} else {
    // befintlig dispatch
}
```

Följdändringar:

* Lägg till `templates` i behörighetslistan för `admin` i
  `config/auth_config.example.php` och i den `auth_config.php` som
  `install.sh:468-477` skriver. `roleHasPermission()` returnerar redan `true` för
  allt när rollen är `admin`, så befintliga installationer påverkas inte.
* Dölj flikarna i `www/admin_ui.php:45-72` med samma `hasPermission()`-anrop. En
  flik som ger 403 vid varje knapptryck är en sämre upplevelse än en flik som
  inte finns — men gömningen är kosmetik, kontrollen ovan är säkerheten.
* Lägg till ett test, `tests/PermissionTest.php`, som går igenom tabellen och
  kontrollerar att `operator` nekas allt utom `read`/`approve`/`scan`. Det är den
  sortens regression som annars återkommer.

---

<a id="s3"></a>
## S3. Oautentiserade tillståndsändringar i bootkedjan — **hög**

Fyra endpoints på port 80 skriver till inventariet utan någon kontroll av vem som
frågar, utöver att MAC:en finns:

| Endpoint | Skriver | Effekt av missbruk |
|---|---|---|
| `deployment_complete.php:66` | `deployment_status = deployed`, `progress = 100` | En pågående installation markeras klar. Hosten bootar lokalt vid nästa omstart i stället för att installera — `boot.ipxe.php:210` vägrar `deployed`-hostar. Installationen avbryts tyst. |
| `boot.cfg.php:127`, `boot.ipxe.php:283` | `approved` → `deploying` | Byter status på en godkänd host som inte har bootat |
| `progress.php:44` | `progress`, `progress_text` | Kosmetiskt; stegen är whitelistade och `storeSetProgress()` går aldrig bakåt |
| `generate_kickstart.php:109` | `last_seen`, `serial_number` | Skriver angriparens sträng i serienummerfältet (filtrerat till `\x20-\x7E`) |

Ingen av dem är exploaterbar för att *ta över* något, men den första räcker för
att sabotera varje utrullning på nätet, och det upptäcks som "installationen
hängde sig" snarare än som ett angrepp.

### Åtgärd

Samma engångstoken som [S1](#s1), utsträckt till `progress.php` och
`deployment_complete.php` — kickstart-mallarna bygger de URL:erna själva och kan
få tokenen inrenderad:

```
/bin/curl -s "{{SERVER_URL}}/progress.php?mac={{MAC_ADDRESS}}&t={{BOOT_TOKEN}}&step=firstboot"
/bin/curl -s "{{SERVER_URL}}/admin/deployment_complete.php?mac={{MAC_ADDRESS}}&t={{BOOT_TOKEN}}"
```

`{{BOOT_TOKEN}}` läggs till i `$variables` i `generate_kickstart.php:156` och i
CI-jobbet `templates` som kontrollerar att varje token i en mall faktiskt sätts.
Tokenen ska då leva till dess `deployment_complete` konsumerar den, inte till dess
kickstarten hämtats — annars fungerar inte callbacken.

Lägg dessutom ratelimit i nginx på bootendpointsen. De anropas några gånger per
host och installation, inte tusentals:

```nginx
limit_req_zone $binary_remote_addr zone=boot:1m rate=30r/m;

location = /deployment_complete.php {
    limit_req zone=boot burst=5 nodelay;
    ...
}
```

---

<a id="s4"></a>
## S4. `deployment_complete.php` sover 10 sekunder per request — **hög**

`www/deployment_complete.php:87`

```php
// Give the host a moment to finish booting before touching BIOS settings.
sleep(10);

if (enableSecureBoot($mac)) {
```

Endpointen är oautentiserad och nåbar från hela provisioneringsnätet. Varje
request håller en php-fpm-worker i minst 10 sekunder, och `enableSecureBoot()`
lägger till ett `python3 -c 'import redfish'`-anrop plus ett Redfish-anrop mot
iLO med en TCP-timeout ovanpå det.

Debians `pm.max_children` ligger på 5 som standard. Ett tiotal parallella
requests tar ned **hela webbservern** — inklusive admin-UI:t, REST-API:t och
bootkedjan för alla andra hostar. En `while true; do curl … & done` från en enda
maskin räcker.

Det är också fel oavsett angripare: en HTTP-handler ska inte sova, och secure
boot-återaktiveringen är ett långsamt sidoeffektjobb som inte hör hemma i
request-cykeln.

### Åtgärd

Svara direkt och gör jobbet asynkront:

1. `deployment_complete.php` skriver statusen och köar återaktiveringen:
   ```php
   storeUpdateHost($mac, ['secure_boot_status' => 'pending-enable']);
   exit('SUCCESS: deployment complete');
   ```
2. En systemd-timer (var 30:e sekund) kör ett litet PHP-skript som plockar
   hostar med `secure_boot_status = 'pending-enable'` vars `deployment_time` är
   mer än 10 sekunder gammal och kör `runSecureBootManager($mac, true)`.
   Sidoeffekten: det överlever också en omstart av php-fpm mitt i, vilket dagens
   variant inte gör.
3. Ratelimit enligt [S3](#s3) som andra lager.

Om den asynkrona vägen känns för stor just nu är minsta möjliga fix att flytta
`sleep(10)` **efter** svaret, med `fastcgi_finish_request()` — men det löser inte
worker-uttömningen, bara den upplevda svarstiden.

---

<a id="s5"></a>
## S5. Loginformuläret verifierar aldrig CSRF-token — **hög**

`www/login.php:24-63` och `:107`

Formuläret renderar `csrfField()`, men POST-hanteraren anropar aldrig
`verifyCsrfToken()`. Token skickas alltså med och slängs.

Konsekvensen är login-CSRF: en angripare kan få en operatörs webbläsare att logga
in på **angriparens** konto. Operatören arbetar sedan i en session angriparen
kontrollerar — allt som godkänns, varje uppladdad mall, varje credential som
skrivs in, hamnar i angriparens kontext, och sessionen kan läsas av den som
skapade den.

Dashboarden gör det rätt (`admin_dashboard.php:94`). Loginsidan är den enda POST
i trädet som inte gör det.

### Åtgärd

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!verifyCsrfToken($_POST)) {
        auth_log("Login attempt with an invalid CSRF token from $clientIp", 'WARNING');
        $error = 'Your session has expired. Please try again.';
    } else {
        // befintlig logik
    }
}
```

`startAdminSession()` körs redan på rad 14, så tokenen finns i sessionen när
formuläret renderas.

---

<a id="s6"></a>
## S6. Brute force-skyddet kringgås genom att slänga kakan — **hög**

`www/login.php:31-48`

```php
$attempts = (int)($_SESSION['login_attempts'] ?? 0);
$lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
```

Räknaren ligger i klientens egen session. En klient som inte skickar tillbaka
`AUTODEPLOYSESSID` får en tom session vid varje försök, och därmed alltid
`$attempts = 0`. `curl` utan cookie-jar gör det som standard:

```bash
for pw in $(cat wordlist); do
  curl -sk -d "action=login&username=admin&password=$pw" https://deploy/admin/login.php
done
```

Ingen strypning, ingen låsning, inget som ens loggas som misstänkt utöver en rad
per försök i `auth.log`.

Skyddet fungerar alltså bara mot den som redan uppträder som en webbläsare —
alltså inte mot den man vill skydda sig mot.

### Åtgärd

Serversidig räknare, per användarnamn **och** per käll-IP. Databasen finns redan:

```sql
CREATE TABLE IF NOT EXISTS login_attempts (
    key          TEXT PRIMARY KEY,   -- "user:admin" eller "ip:10.0.0.5"
    failures     INTEGER NOT NULL DEFAULT 0,
    locked_until INTEGER NOT NULL DEFAULT 0,
    updated      INTEGER NOT NULL
);
```

* Räkna upp båda nycklarna vid varje misslyckande, nollställ båda vid lyckad
  inloggning.
* Lås när endera passerar 5, med exponentiell backoff upp till 15 minuter.
* Städa poster äldre än ett dygn i samma transaktion, så tabellen inte växer.
* Svara **likadant** oavsett om kontot är låst eller lösenordet fel: annars blir
  låsningen i sig en användarnamnsorakel. Idag skiljer sig meddelandena åt
  (`"Too many failed login attempts…"` vs `"Invalid username or password"`).

Lägg till `limit_req` på loginvägen som andra lager:

```nginx
limit_req_zone $binary_remote_addr zone=login:1m rate=10r/m;

location = /admin/login.php {
    limit_req zone=login burst=5 nodelay;
    include fastcgi_params;
    ...
}
```

---

<a id="s7"></a>
## S7. Admin-UI och API exponeras på provisioneringsnätet — **medel**

`nginx.conf:25-26, 142-143` — `listen 80 default_server;` och `listen 443 ssl;`
utan adress betyder alla interface. Loginsidan och `/api/v1/` syns därmed på det
nät där oautentiserade ESXi-installationer och godtycklig inkopplad hårdvara
sitter.

Behandlas i sin helhet i [`network-segmentation.md`](network-segmentation.md), med
förslag på `listen`-bindningar per adress, `allow`/`deny`, nya install.sh-flaggor
och nftables-regler.

Fyndets betydelse i den här listan är att det **multiplicerar** S1, S5 och S6:
varje sak som är farlig på provisioneringsnätet blir farligare när även
inloggningen ligger där.

---

<a id="s8"></a>
## S8. `local-helpers`-token har admin-roll och är läsbar för www-data — **medel**

`lib/utils.php:742` (`apiLocalToken()`), `install.sh:506-532`,
`www/api.php:433-491`

Admin-UI:t skickar en API-token till Python-hjälparna. Tokenen ligger i klartext i
`config/api_local_token` (0640 `root:www-data`) och registreras med
`'role' => 'admin'`.

Kedjan blir:

```
kodexekvering som www-data
  → läser config/api_local_token
  → GET /api/v1/credentials/esxi   (kräver 'settings', admin har allt)
  → ESXi-rootlösenordet i klartext, plus iLO-kontot
```

Krypteringen at rest (`lib/secrets.php`) skyddar mot en stulen backup, en snapshot
eller ett support-bundle — det är vad den är byggd för och det gör den bra. Den
skyddar inte mot kodexekvering i webbservern, eftersom nyckeln ligger i samma
katalog och läses av samma användare. Det är en rimlig avvägning, men rollen på
hjälpar-tokenen gör hålet större än det behöver vara: hjälparna behöver
**iLO**-kontot, inte ESXi-rootlösenordet.

### Åtgärd

Ge tokenen en egen, smal roll:

```php
'roles' => [
    // ...
    'helper' => [
        'description' => 'The Python helpers: iLO scan and secure boot',
        'permissions' => ['read', 'write', 'ilo-credentials'],
    ],
],
'api_tokens' => [
    'local-helpers' => [
        'token_hash' => '…',
        'role'       => 'helper',
    ],
],
```

och dela behörighetskontrollen i `apiHandleCredentials()` (`www/api.php:444`):

```php
apiRequire($type === 'ilo' ? 'ilo-credentials' : 'settings');
```

`roleHasPermission()` returnerar redan `true` för allt när rollen är `admin`, så
`settings` fortsätter räcka för en riktig administratör.

Överväg också att låta `secure_boot_manager.py` få credentials på stdin från PHP i
stället för att hämta dem över API:t. Då behöver hjälparen ingen token som når
credentials-endpointen alls, och `apiLocalToken()` kan smalna till `read`+`write`.

---

<a id="s9"></a>
## S9. Hjälpar-token passerar genom ett shell-kommando — **medel**

`lib/utils.php:765-772, 793-803`, `www/hardware_functions.php:20-29`

```php
return 'AUTODEPLOY_API_TOKEN=' . escapeshellarg($token) . ' ';
...
exec($command, $output, $returnCode);
```

`exec()` startar `/bin/sh -c "<sträng>"`. Escapingen är korrekt, så det här är
ingen injektionsväg — men tokenen finns i sh:s minne, i eventuell
process-accounting (`acct`), i `strace`-utdata och i en core dump. Miljövariabler
syns inte i `ps` för andra användare på Linux, vilket är det som räddar
konstruktionen, men den vilar på en detalj som inte behöver vilas på.

### Åtgärd

`proc_open()` med en explicit miljöarray. Inget shell, ingen escaping, ingen
kommandorad att läcka:

```php
$process = proc_open(
    [PHP_BINARY === '' ? 'python3' : 'python3',
     AUTODEPLOY_ROOT . '/scripts/secure_boot_manager.py',
     '--mac', $mac, '--action', $action],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['AUTODEPLOY_API_TOKEN' => $token,
     'AUTODEPLOY_ROOT'      => AUTODEPLOY_ROOT,
     'PATH'                 => getenv('PATH') ?: '/usr/bin:/bin']
);
```

Array-formen på argument 1 kräver PHP 7.4+ och kringgår shellet helt. Samma
ändring i `runIloScanner()`, där `timeout(1)` då ersätts av en egen timeout i
läsloopen eller behålls som första argument i arrayen.

---

<a id="s10"></a>
## S10. `is_uploaded_file()` saknas i ISO-uppladdningen — **medel**

`www/api.php:521-536`

```php
$upload = $_FILES['image'] ?? null;
...
$result = imageInstall($upload['tmp_name'], $version, ...);
```

Mall-uppladdningen gör det rätt (`www/templates.php:1640`); bild-uppladdningen gör
det inte. `$_FILES` fylls av PHP självt, så det finns ingen känd väg att styra
`tmp_name` — men `is_uploaded_file()` är precis den kontroll som gör att
påståendet "det går inte" kan verifieras i stället för resoneras fram, och den
kostar ingenting.

### Åtgärd

```php
if (!is_array($upload)
    || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_uploaded_file($upload['tmp_name'])) {
    apiError('Expected a multipart upload in the "image" field: ' . apiUploadError($upload), 400);
}
```

---

<a id="s11"></a>
## S11. ISO-extrahering av opålitligt arkiv utan sandlåda — **medel**

`lib/images.php:129-160`

```php
$command = sprintf($template, escapeshellarg($isoPath), escapeshellarg($targetDir)) . ' 2>&1';
exec($command, $output, $code);
```

En uppladdad ISO packas upp med bsdtar, 7z eller xorriso direkt i
`esxi/<version>/`. Vilket verktyg som väljs beror på vad som är installerat
(`imageAvailableExtractor()`), och de tre hanterar absoluta sökvägar och `../` i
arkivposter olika. Uppackning av opålitliga arkiv är en klassisk väg ut ur
måldirectoryt.

Åtgärden kräver `settings`-behörighet, så det är inte en väg in för en anonym
angripare — men i kombination med [S2](#s2) är "settings" idag detsamma som "alla
inloggade".

### Åtgärd

* Packa upp i en tom temporärkatalog under `esxi/.staging/<random>/`, kontrollera
  resultatet, och `rename()` in det först därefter. Det gör också
  rollbacken i `imageInstall()` triviallt korrekt — idag anropas
  `imageRemoveDirectory($version)` på en halvutpackad katalog.
* Kontrollera efter uppackningen att inget realpath under staging-katalogen pekar
  utanför den (symlänkar i mediat).
* Lägg `--no-absolute-paths` respektive motsvarande flagga per verktyg i
  `imageExtractorCandidates()`.
* Överväg att göra SHA-256-kontrollen obligatorisk i stället för valfri.
  `imageInstall():437` loggar idag bara en varning när ingen hash angavs; för
  installationsmedia som varje host bootar är det en svag default.

---

<a id="s12"></a>
## S12. Klartextlösenord kvar som fallback — **medel**

`www/generate_kickstart.php:143-145`

```php
$rootPassword = $esxiCredentials['root_password']
    ?? $globalConfig['deployment']['esxi_root_password']
    ?? '';
```

`global_config.json` omfattas inte av krypteringen i `lib/secrets.php` — bara
`credentials.json` gör det (`storeSecretFieldPaths()`). Fältet är märkt
"deprecated" i kommentaren men är fortfarande en fungerande väg att lagra
rootlösenordet i klartext, i en fil som dessutom skrivs om av admin-UI:t vid
varje inställningsändring.

### Åtgärd

Ta bort fallbacken. `install.sh` skriver aldrig fältet, och
`storeLoadCredentials('esxi', $mac)` är den väg som finns. Blir resultatet tomt är
`ksAbort()` på rad 149 rätt svar — bättre att en installation stannar med
"inget rootlösenord konfigurerat" än att den lyckas med ett lösenord som ligger
oskyddat.

Om bakåtkompatibilitet behövs: läs fältet, logga en `WARNING`, och migrera in det i
`credentials.json` vid första läsningen — samma mönster som
`secretDecryptOrPassThrough()` redan använder för legacy-klartext.

---

<a id="s13"></a>
## S13. `get_log.php` saknar behörighetskontroll — **låg**

`www/get_log.php:16-21` kontrollerar `currentUser()` men inte `hasPermission()`.
Varje inloggad användare, oavsett roll, kan läsa varje `*.log` under `logs/` —
inklusive `auth.log` (användarnamn, käll-IP:n, misslyckade inloggningar) och
`api.log` (vilka tokennamn som gör vad).

Sökvägshanteringen är i övrigt korrekt: `basename`-kontroll, regex på filnamnet,
`safePathJoin()` med `$mustExist`, storleksgräns på 200 KB.

### Åtgärd

Samma mönster som `www/host_status.php:34`:

```php
if (!hasPermission('read')) {
    http_response_code(403);
    exit('Insufficient permissions');
}
```

---

<a id="s14"></a>
## S14. CSP tillåter `unsafe-inline` för script — **låg**

`nginx.conf:177`

```
script-src 'self' 'unsafe-inline'
```

Det tar bort den del av CSP:n som är värd något mot XSS. Direktivet finns där för
att UI:t har inline-`<script>`-block och `onclick`-attribut på flera ställen
(`www/templates.php`, `www/hosts.php`, `www/scan.php`).

Utdatahanteringen är i övrigt genomgående korrekt — `h()` och `jsValue()` används
konsekvent, och `dashboard.php:229` escapar loggraden **före** den lägger på
`<span>`-taggar, vilket är rätt ordning. Det finns alltså ingen känd XSS att
skydda mot. Men CSP:n är just till för den som inte är känd.

### Åtgärd

Flytta ut inline-JS till filer under `www/js/` och ersätt `onclick` med
`addEventListener` mot `data-`-attribut — mönstret finns redan i
`www/hosts.php:278` (`data-edit-host` + `jsValue()`). Släpp därefter
`'unsafe-inline'`. Inline-CSS (`style-src`) är en mindre risk och kan vänta.

---

<a id="s15"></a>
## S15. `alias` med regex-capture på `/esxi/` — **låg**

`nginx.conf:52-58`

```nginx
location ~ ^/esxi/(?<esxi_version>[A-Za-z0-9._-]+)/(?<esxi_file>.+)$ {
    alias /srv/autodeploy/esxi/$esxi_version/$esxi_file;
```

`alias` med en ospärrad `.+`-capture är det klassiska nginx-traversalmönstret.
Här är det stoppat: nginx normaliserar URI:n — inklusive procent-avkodade
punktsegment — innan location-matchning sker, så `/esxi/8.0U3/../../config/x`
matchar aldrig det här blocket. Kommentaren i filen konstaterar samma sak.

Fyndet står med ändå därför att skyddet ligger i nginx normaliseringsbeteende och
inte i konfigurationen, och därför inte överlever en omskrivning av blocket eller
en framtida `merge_slashes off`.

### Åtgärd

```nginx
location ~ ^/esxi/(?<esxi_version>[A-Za-z0-9][A-Za-z0-9._-]*)/(?<esxi_file>[^.][^\\]*)$ {
    root /srv/autodeploy;
    try_files /esxi/$esxi_version/$esxi_file =404;
    default_type application/octet-stream;
    autoindex off;
}
```

`root` + `try_files` går genom nginx sökvägshantering i stället för runt den, och
`default_type application/octet-stream` gör att inget i installationsmediat
någonsin kan tolkas som något annat än en fil att ladda ner.

---

<a id="s16"></a>
## S16. Obegränsad e-post vid autoregistrering — **låg**

`www/boot.ipxe.php:130-142`

Varje ny MAC som autoregistreras skickar ett mail till adressen i
`auto_registration.notification_email`. Endpointen är oautentiserad och MAC:en
kommer från query-strängen, så en angripare på provisioneringsnätet kan generera
ett mail per request — mot operatörens inkorg, från appliancens avsändare.

### Åtgärd

Strypning i samma transaktion som registreringen: max N mail per timme (t.ex. 20),
räknat i databasen. Alternativt: skicka en sammanfattning per timme i stället för
ett mail per host, vilket också är trevligare att ta emot vid en
massprovisionering.

---

<a id="s17"></a>
## S17. Ingen absolut sessionslivslängd — **låg**

`lib/auth.php:194, 225` — sessionen går ut efter 30 minuters **inaktivitet**. En
flik som pollar `host_status.php` (dashboarden gör det) förnyar
`last_activity` och håller därmed sessionen vid liv i all oändlighet.

### Åtgärd

Sätt `$_SESSION['created'] = time()` i `establishSession()` och avvisa i
`authenticate()`/`currentUser()` när den passerat ett absolut tak, förslagsvis
8 timmar. Överväg också `session_regenerate_id()` periodiskt, inte bara vid
inloggning.

---

<a id="s18"></a>
## S18. Timing-utjämningen i `verifyCredentials()` är sprö — **info**

`lib/auth.php:147-152`

```php
$hash = $user['password_hash']
    ?? '$2y$12$usesomesillystringforsalttoavoidtiminglea.kQ8yQ0J6zVQm0Ck5xJZ.G';
```

Avsikten är rätt och genomförandet fungerar idag. Men dummyhashen är hårdkodad som
bcrypt cost 12, medan riktiga hashar skapas med `PASSWORD_DEFAULT`
(`generatePasswordHash()`). Byter PHP default till argon2id, eller höjer man
kostnaden, blir tiderna olika igen och orakelt återkommer — tyst.

### Åtgärd

Generera dummyhashen med samma algoritm som används för riktiga lösenord, en gång
per process:

```php
static $dummy = null;
$dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
$hash = $user['password_hash'] ?? $dummy;
```

---

## Vad som är bra, och bör förbli så

Värt att skriva ned, eftersom det annars är det som råkar bort i nästa
omskrivning:

* **`lib/secrets.php`** — XChaCha20-Poly1305, nonce per värde, taggat format,
  och en trasig nyckel avvisas i stället för att ersättas. Motiveringen till att
  inte välja AES-GCM (beroendet på AES-NI) står i filen. Legacy-klartext läses
  och migreras vid nästa skrivning i stället för att låsa ut operatören.
* **`lib/api_auth.php`** — SHA-256-digest i stället för bcrypt för token, med
  motiveringen skriven; `hash_equals()` i en loop som inte kortsluts vid träff.
* **`renderTemplate()`** (`lib/utils.php:598`) — en enda `preg_replace_callback`
  i stället för `str_replace()` med parallella arrayer, med kommentaren som
  förklarar exakt vilken bugg det var: ett datastore-namn som råkade heta
  `{{ROOT_PASSWORD_HASH}}` fick hashen inrenderad.
* **`safePathJoin()`** och de tre valideringsfunktionerna kring mallnamn —
  `basename`-jämförelse, regex och realpath-kontroll i lager.
* **`storeProgressSteps()`** — en fast tabell, så en klient inte kan rapportera
  sig till 99 % eller påstå sig färdig.
* **CSRF på dashboardens POST-router** — en kontroll, före all dispatch.
* **Utdataescaping** — `h()` och `jsValue()` används konsekvent; ingen rå
  interpolation av host- eller loggdata hittades.
* **`sanitizeIpxeText()`** och radbrytningsfiltret i `logMessage()` — stoppar
  loggförfalskning och injektion i iPXE-skript.

---

## Föreslagen ordning

Genomfört, i den ordning det gjordes:

| Fynd | Vad som ändrades |
|---|---|
| [S5](#s5) | `verifyCsrfToken()` som första gren i `www/login.php` |
| [S13](#s13) | `hasPermission('read')` i `www/get_log.php` |
| [S10](#s10) | `is_uploaded_file()` före `imageInstall()` i `www/api.php` |
| [S6](#s6) | `login_attempts`-tabell + `authThrottle*()` i `lib/auth.php`; `tests/LoginThrottleTest.php` |
| [S2](#s2) | `actionPermission()`/`tabPermission()` i `lib/auth.php`, kontrollerade i `www/admin_dashboard.php` före dispatch och före rendering; navigationen döljer flikar; ny `templates`-behörighet; `tests/PermissionTest.php` |
| [S1](#s1)+[S3](#s3) | `boot_token`-kolumner, `storeIssueBootToken()`/`storeVerifyBootToken()`/`storeClearBootToken()`, token krävd av `/ks.cfg`, `progress.php` och `deployment_complete.php`; `tests/BootTokenTest.php` |

Kvar, i ordning efter värde per insats:

| Steg | Fynd | Insats |
|---|---|---|
| 1 | [S7](#s7) nätverksbindningar | se `network-segmentation.md` |
| 2 | [S4](#s4) asynkron secure boot | en systemd-timer, ett litet skript |
| 3 | [S8](#s8), [S9](#s9), [S11](#s11), [S12](#s12) | var för sig avgränsade |
| 4 | [S14](#s14)–[S18](#s18) | härdning, ingen brådska |

Samtliga kritiska och höga fynd är åtgärdade. Det som återstår är avgränsat och
kan tas i den takt som passar; [S7](#s7) är det som betyder mest i produktion,
och är konfiguration snarare än kod.
