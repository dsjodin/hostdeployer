# Kodgranskning — hostdeployer, juli 2026

Korrekthet, robusthet och struktur. Säkerhetsfynden ligger separat i
[`SECURITY-REVIEW-2026-07.md`](SECURITY-REVIEW-2026-07.md), installationsfelen i
[`install-troubleshooting.md`](install-troubleshooting.md) och nätverksfrågan i
[`network-segmentation.md`](network-segmentation.md).

Fortsättning på [`CODE-REVIEW.md`](CODE-REVIEW.md), som gjorde den förra
omgången. Kodbasen är i väsentligt bättre skick än den beskrivningen antyder:
duplicerade helpers är borta, storage-lagret är samlat i `lib/store.php`,
inventariet ligger i SQLite med transaktioner, och kommentarerna förklarar
genomgående *varför* något är som det är i stället för att upprepa vad koden
säger. Det är ovanligt, och det är den egenskapen granskningen nedan försöker
bevara.

15 testfiler, PHPStan nivå 5, shellcheck och ruff i CI. Grunden finns.

| # | Fynd | Grad |
|---|---|---|
| [C1](#c1) | `storeMutateHosts()` skriver om hela inventariet vid varje ändring | Hög |
| [C2](#c2) | Bootendpoints läser hela inventariet trots indexerad uppslagning | Hög |
| [C3](#c3) | Två vägar att radera en host, med olika sidoeffekter | Medel |
| [C4](#c4) | `apiRespondToActionResult()` läser en nyckel som inte alltid finns | Medel |
| [C5](#c5) | Tre olika listor över var `boot.cfg` kan ligga | Medel |
| [C6](#c6) | Uppladdade mallar valideras inte mot generatorns tokenlista | Medel |
| [C7](#c7) | `imageDirectorySize()` statar hela ESXi-trädet vid varje rendering | Medel |
| [C8](#c8) | Saknade `??` ger `Undefined array key`-varningar i admin-UI:t | Låg |
| [C9](#c9) | Tre implementationer av filstorleksformatering | Låg |
| [C10](#c10) | `www/templates.php` är 1773 rader | Låg |
| [C11](#c11) | Död kod i `login.php` | Låg |
| [C12](#c12) | `switch`-fall som förlitar sig på att `apiRespond()` avslutar | Låg |
| [C13](#c13) | Spårfiler i repot: `tree.txt`, dubblerad `boot.cfg` | Låg |
| [C14](#c14) | PHP-versionen: 8.1 i CI, 8.4 hårdkodad i install.sh | Låg |
| [C15](#c15) | Testerna täcker inga säkerhetsgränser | Medel |

---

<a id="c1"></a>
## C1. `storeMutateHosts()` skriver om hela inventariet vid varje ändring

`lib/store.php:348-388`

```php
foreach ($hosts as $host) {
    if (is_array($host)) {
        storeUpsertHostRow($pdo, $host);
    }
}
```

Varje mutation läser in alla hostar, kör callbacken, och skriver sedan tillbaka
**samtliga** — plus en `DELETE` + N `INSERT` mot `host_macs` per host
(`storeUpsertHostRow():193-204`).

Fyra anropare gör detta för att ändra ett enda fält på en enda host:

| Anropare | Vad den faktiskt ändrar |
|---|---|
| `processApproveHostAction()` (`host_functions.php:381`) | en host |
| `processReinstallHostAction()` (`host_functions.php:459`) | en host |
| `processDeleteHostAction()` (`host_functions.php:272`) | en host |
| `processAddHostAction()` (`host_functions.php:172`) | en host |

Det var rätt konstruktion när inventariet var en JSON-fil som ändå skrevs i sin
helhet. Nu motverkar det poängen med flytten till SQLite: ett godkännande i ett
estat på 500 hostar blir ~1500 satser i en `BEGIN IMMEDIATE`-transaktion, som
under tiden blockerar varje bootande host från att uppdatera sin status.

Dokstringen säger det redan: *"new code should prefer the narrower functions
below"*. Ingen gör det.

### Åtgärd

Skriv om de fyra anroparna mot `storeUpdateHost()` och `storeDeleteHost()`.
`processApproveHostAction()` blir till exempel:

```php
$existing = storeFindHost($mac);
if ($existing === null) {
    $result['error'] = "Host with MAC '$mac' not found";
    return $result;
}

$update = [
    'hostname'           => $hostname,
    'fqdn'               => $fqdn,
    'management_ip'      => trim((string)$postData['management_ip']),
    'management_netmask' => trim((string)($postData['management_netmask'] ?? '255.255.255.0')),
    'management_gateway' => trim((string)($postData['management_gateway'] ?? '')),
    'deployment_type'    => $deploymentType,
    'deployment_status'  => 'approved',
    'approved_time'      => date('Y-m-d H:i:s'),
    'vlans'              => [
        'management' => (int)($postData['vlan_mgmt'] ?? 0),
        'vmotion'    => ($deploymentType === 'standard' && $vmotionIp !== '')
                            ? (int)($postData['vlan_vmotion'] ?? 0) : 0,
        'storage'    => (int)($existing['vlans']['storage'] ?? 0),
    ],
];
```

`storeUpdateHost()` gör redan `array_merge` mot den befintliga posten inuti sin
transaktion, så semantiken bevaras. Behåll `storeMutateHosts()` för
`storeMergeDiscoveredHosts()`, som verkligen arbetar över hela listan.

`storeUpsertHostRow()` bör dessutom sluta göra `DELETE` + `INSERT` på
`host_macs` när listan är oförändrad — jämför med det som redan finns och skriv
bara vid skillnad.

---

<a id="c2"></a>
## C2. Bootendpoints läser hela inventariet trots indexerad uppslagning

`www/boot.ipxe.php:80,91,146-147` och `www/generate_kickstart.php:53,74`

```php
$hostsConfig = storeLoadHostsConfig();      // SELECT * FROM hosts
...
$host = findHostByMac($mac, $hostsConfig);  // linjär sökning i PHP
```

`storeFindHost()` (`lib/store.php:304`) gör exakt samma sak med två indexerade
frågor. `www/boot.cfg.php:70` använder den redan. De två endpoints som anropas
oftast — en gång per bootande host, och `boot.ipxe.php` en gång per retry i en
loop med 60 sekunders intervall — gör det inte.

`boot.ipxe.php` läser dessutom hela inventariet en **andra** gång efter en
autoregistrering (rad 146).

Kommentaren i `lib/db.php:121` beskriver just det här som problemet
`host_macs`-tabellen skulle lösa: *"as the JSON array it replaced, it was a scan
of every host on every boot request"*. Tabellen finns; anroparna hänger kvar.

### Åtgärd

Byt till `storeFindHost($mac)` på båda ställena. `$hostsConfig` behövs då inte
alls i `generate_kickstart.php`, och i `boot.ipxe.php` bara till
null-kontrollen — som blir `storeFindHost()` returnerar null.

Efter det kan `findHostByMac()` och `hostMatchesMac()` i `lib/utils.php` behållas
för `storeMutateHosts()`-callbackarna, där de arbetar på en array som redan är
inläst.

---

<a id="c3"></a>
## C3. Två vägar att radera en host, med olika sidoeffekter

`lib/store.php:498-530` (`storeDeleteHost()`) och
`www/host_functions.php:262-305` (`processDeleteHostAction()`)

Båda tar bort en host. Bara den ena används.

`storeDeleteHost()` raderar raden och anropar `storeDeleteHostCredentials()`.
`processDeleteHostAction()` går i stället genom `storeMutateHosts()` med en
`array_splice()`, och duplicerar sedan credential-städningen inline
(rad 295-299). `storeDeleteHost()` anropas ingenstans i trädet — den enda vägen
en host raderas är den som inte använder den.

Två implementationer av samma sak, där den ena är död, är den konstruktion som
gör att en rättning hamnar i fel. Det finns redan ett exempel på precis det i
`CODE-REVIEW.md` §4: `via_go` hade boot.cfg-omskrivningen implementerad två
gånger och en fix nådde bara den ena.

### Åtgärd

Låt `processDeleteHostAction()` anropa `storeDeleteHost()` och ta bort den
inline-kopierade credential-städningen. Skillnaden i beteende — den ena returnerar
`false` när hosten inte fanns, den andra `$found = false` — mappar rakt av.

---

<a id="c4"></a>
## C4. `apiRespondToActionResult()` läser en nyckel som inte alltid finns

`www/api.php:93-102`

```php
function apiRespondToActionResult(array $result, $body = null) {
    $error = $result['error'];
```

Utan `??`. Handlarna returnerar idag alltid `error`, men de returnerar olika
former: `processSecureBootAction()` ger tre nycklar
(`message`, `error`, `scanOutput`), de övriga två. Docblocken säger
`array{message?: string, error?: string}` — alltså att båda är valfria — vilket
gör raden fel även enligt sin egen dokumentation.

En handler som returnerar tidigt utan att sätta nyckeln ger `Undefined array
key`-varning i `php_errors.log` och `$error = null`, som sedan jämförs med
`!== ''` och behandlas som ett fel med status 400 och `null` som meddelande.

### Åtgärd

```php
$error = (string)($result['error'] ?? '');
```

Och medan man ändå är där: gör returtypen enhetlig. En liten klass eller ett
namngivet array-format som PHPStan kan kontrollera vore bättre än fyra funktioner
som var och en råkar returnera nästan samma sak.

---

<a id="c5"></a>
## C5. Tre olika listor över var `boot.cfg` kan ligga

| Fil | Kandidater |
|---|---|
| `lib/images.php:180` | `boot.cfg`, `BOOT.CFG`, `efi/boot/boot.cfg` |
| `www/boot.cfg.php:88` | `boot.cfg`, `efi/boot/boot.cfg` |
| `www/config_functions.php:59` | bara `boot.cfg` |
| `www/boot.ipxe.php:240` | bara `boot.cfg` |

Konsekvensen är konkret: ett installationsmedia som bara har
`efi/boot/boot.cfg` godkänns av `imageLooksBootable()` vid uppladdning, bootar
via `boot.cfg.php`, men visas som **inte tillgängligt** i admin-UI:t
(`getEsxiVersions()`) och avvisas av `boot.ipxe.php` med "ESXi version X is not
installed".

Samma sak gäller laddarens sökväg, som räknas upp två gånger med identisk lista:
`www/boot.ipxe.php:297` och `www/mboot.efi.php:70`.

### Åtgärd

En funktion i `lib/bootcfg.php`, som är det naturliga hemmet:

```php
/**
 * Var boot.cfg kan ligga i ett uppackat installationsmedia.
 *
 * ESXi lägger den i roten och igen under efi/boot/, och hur den ser ut i
 * filsystemet beror på hur mediat packades upp. Listan finns på ett ställe
 * därför att en version som accepteras vid uppladdning måste vara samma
 * version som går att boota.
 */
function bootCfgCandidates($imageDir) { ... }
function bootLoaderCandidates($imageDir) { ... }
```

och fyra anropsställen som använder den.

---

<a id="c6"></a>
## C6. Uppladdade mallar valideras inte mot generatorns tokenlista

CI-jobbet `templates` (`.github/workflows/ci.yml`) kontrollerar att varje
`{{TOKEN}}` i `templates/*.cfg` faktiskt sätts av `generate_kickstart.php`.
Motiveringen står i jobbet: *"A kickstart that reaches a host with an
unsubstituted {{TOKEN}} in it is a failed install, and the renderer silently
leaves unknown tokens alone."*

Det är rätt kontroll på fel ställe. Mallar redigeras och laddas upp genom
admin-UI:t (`www/templates.php:1482, 1629`) och går aldrig genom CI. En mall med
`{{SERVER_URI}}` sparas utan invändning och når installern med literalen kvar.

### Åtgärd

Flytta kontrollen in i applikationen och låt CI anropa samma kod:

```php
/**
 * @return string[] Tokens mallen använder som generatorn inte sätter
 */
function templateUnknownTokens($content) {
    preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', stripComments($content), $m);
    return array_values(array_diff(
        array_unique($m[1]),
        array_merge(kickstartVariableNames(), ['ELSE', 'ENDIF', 'IF'])
    ));
}
```

`kickstartVariableNames()` bryts ut ur `generate_kickstart.php:156` så att
listan över variabler finns på ett ställe. `save_template` och `upload_template`
varnar (inte avvisar — en mall kan vara ett halvfärdigt utkast) och visar vilka
tokens som inte kommer att fyllas i.

---

<a id="c7"></a>
## C7. `imageDirectorySize()` statar hela ESXi-trädet vid varje rendering

`lib/images.php:381-399`, anropad från `imageList():366`

Ett uppackat ESXi-media är ~500 filer. `imageList()` går igenom dem alla, per
installerad version, varje gång:

* settings-fliken renderas
* `GET /api/v1/images` anropas
* `GET /api/v1/images/{version}` anropas — som dessutom itererar hela
  `imageList()` för att hitta en version (`www/api.php:595`)

`imageList()` anropar dessutom `imageLooksBootable()` per version, som läser och
parsar `boot.cfg`.

### Åtgärd

Räkna storleken en gång vid installation och skriv den i
`global_config.json` bredvid `path` och `description` (`imageRegister():297`).
Behåll `imageDirectorySize()` för en explicit "räkna om"-knapp. `present` och
`bootable` är billiga nog att kontrollera live.

---

<a id="c8"></a>
## C8. Saknade `??` ger `Undefined array key`-varningar

`global_config.json` skriven av `install.sh:381-427` innehåller inte alla nycklar
som UI:t läser oskyddat:

| Ställe | Läser | Finns i install.sh:s config? |
|---|---|---|
| `www/settings.php:85,135` | `ilo.admin_password` | nej |
| `www/settings.php:238` | `security.secure_boot_enabled` | ja |
| `www/scan.php:42` | `ilo.scan_range_start`, `.._end` | ja (tomma strängar) |
| `www/scan.php:48` | `ilo.admin_user` | ja |

`ilo.admin_password` läses alltså på en nyinstallerad maskin och finns inte —
`display_errors` är av, så det blir en rad i `php_errors.log` per sidladdning i
stället för ett synligt fel. Fungerande, men det gör loggen svårare att läsa när
något verkligt går sönder.

`www/scan.php:42,48` använder dessutom `htmlspecialchars()` utan flaggor i stället
för `h()`. På PHP 8.1+ är defaulten `ENT_QUOTES | ENT_SUBSTITUTE`, så resultatet
är detsamma — men `h()` finns just för att den frågan inte ska behöva ställas.

### Åtgärd

`h($globalConfig['ilo']['admin_password'] ?? '')` genomgående, och `h()` i stället
för `htmlspecialchars()` i `www/scan.php`. Överväg en
`configValue($config, 'ilo.admin_user', 'Administrator')`-helper — UI:t gör
uppslagningar med tre nivåers `??` på ett tjugotal ställen.

---

<a id="c9"></a>
## C9. Tre implementationer av filstorleksformatering

| Funktion | Fil | Enheter |
|---|---|---|
| `getReadableFileSize()` | `lib/utils.php:827` | B…PB, `log()`-baserad |
| `formatFileSize()` | `www/templates.php:190` | B…TB, loop-baserad |
| `formatSize()` | `www/get_log.php:79` | wrappar `getReadableFileSize()` |

Den mellersta muterar dessutom sin `int`-parameter till `float` i loopen.

### Åtgärd

Behåll `getReadableFileSize()`. Ta bort de två andra och byt anropsställena.

---

<a id="c10"></a>
## C10. `www/templates.php` är 1773 rader

Redan noterat som punkt 7 i `CODE-REVIEW.md`:s "Kvar att göra", och fortfarande
den största filen i trädet. Den innehåller:

* sökvägsvalidering (rad 44-114) — den säkerhetskritiska delen
* filsystemsoperationer (rad 122-414)
* CSS i en heredoc (rad 419-507)
* JavaScript i två heredocs (rad 512-620, 1025-1135)
* HTML-rendering (rad 625-1452)
* åtgärdsdispatcher (rad 1462-1740)

Delarna har ingenting med varandra att göra, och den enda som behöver granskas
noga — sökvägsvalideringen — ligger begravd överst.

### Åtgärd

Tre filer:

* `lib/templates.php` — `isValidTemplateName()`, `isValidBackupName()`,
  `resolveTemplatePath()`, `resolveBackupPath()`, `templateNameFromBackup()`,
  `getTemplateFiles()`, `saveTemplateFile()`, `backupTemplateFile()`,
  `restoreTemplateFromBackup()`, `createTemplate()`. Testbart, och naturligt hem
  för `templateUnknownTokens()` från [C6](#c6).
* `www/templates_actions.php` — `processTemplatesActions()`,
  `processDownloadRequest()`.
* `www/templates.php` — bara rendering. CSS till `www/css/admin-custom.css`, JS
  till `www/js/template-editor.js` (vilket också är förutsättningen för att
  släppa `unsafe-inline` i CSP:n, se S14 i säkerhetsgranskningen).

---

<a id="c11"></a>
## C11. Död kod i `login.php`

`www/login.php:65-72`

```php
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . $host;

$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptPath = $scriptPath === '/' ? '' : $scriptPath;
```

Ingen av de fyra variablerna används i resten av filen. `$host` läser dessutom
`HTTP_HOST` utan validering — ofarligt så länge värdet inte används, vilket är
precis det som gör att raden överlever nästa gång någon behöver en bas-URL.

### Åtgärd

Ta bort.

---

<a id="c12"></a>
## C12. `switch`-fall som förlitar sig på att `apiRespond()` avslutar

`www/api.php:267-298`

```php
case 'GET':
    apiRequire('read');
    ...
    apiRespond($host);
    // no break: apiRespond exits

case 'PATCH':
```

Korrekt — `apiRespond()` anropar `exit`. Men konstruktionen betyder att ett
misstag i `apiRespond()` blir en tyst fallthrough där en `GET` utför en `PATCH`.
PHPStan nivå 5 flaggar det inte; nivå 6+ eller en fallthrough-regel skulle.

### Åtgärd

Ett `break;` efter varje `apiRespond()`/`apiError()`. Oåtkomlig kod är billigare
än ett fallthrough som ingen ser. Alternativt `#[NoReturn]`-attribut på
`apiRespond()` och `apiError()`, som PHPStan förstår och som gör
avsikten maskinläsbar.

---

<a id="c13"></a>
## C13. Spårfiler i repot

| Fil | Vad |
|---|---|
| `tree.txt` | en katalogutskrift, versionshanterad |
| `boot.cfg` och `esxi/boot.cfg` | byte-identiska kopior av samma exempelfil |

`install.sh:346-354` rsync:ar båda till `/srv/autodeploy`. `esxi/boot.cfg` hamnar
i roten av bildkatalogen, där `imageList()` inte tittar men en operatör som
felsöker mycket väl kan hitta den och tro att den betyder något.

### Åtgärd

Ta bort `tree.txt` och en av `boot.cfg`-kopiorna. Behåll den under `esxi/` — den
ligger där ett riktigt media skulle ligga och kommenteras därefter. Lägg till en
`.gitattributes` med `export-ignore` på utvecklingsartefakter.

---

<a id="c14"></a>
## C14. PHP-versionen är tre olika saker

| Ställe | Säger |
|---|---|
| `composer.json` | `"php": ">=8.1"` |
| `README.md` "Krav" | PHP 8.1+ |
| `.github/workflows/ci.yml` | syntaxkoll på 8.1, tester på 8.3 |
| `install.sh:34` | `readonly PHP_VERSION=8.4` |

install.sh `die`:ar på `[ -d "$PHP_INI_DIR" ] || die` (rad 573) om `/etc/php/8.4/`
inte finns. Motiveringen — att trixie har 8.4 i main — står i kommentaren och är
god, men gör skriptet obrukbart på varje maskin som råkar ha en annan version,
även om applikationen fungerar där.

### Åtgärd

Autodetektera med 8.4 som preferens:

```bash
detect_php_version() {
    local want=8.4 v
    [ -d "/etc/php/$want/fpm" ] && { echo "$want"; return; }
    for v in $(ls -1 /etc/php 2>/dev/null | sort -Vr); do
        [ -d "/etc/php/$v/fpm" ] && { echo "$v"; return; }
    done
    return 1
}
PHP_VERSION="$(detect_php_version)" || die "No php-fpm found under /etc/php"
```

Och en kontroll att den funna versionen är ≥ 8.1, som `composer.json` kräver.

---

<a id="c15"></a>
## C15. Testerna täcker inga säkerhetsgränser

15 testfiler, och de täcker de rena funktionerna väl: MAC-normalisering,
netmask, `safePathJoin()`, mallrendering, `boot.cfg`-parsning, lösenordshashning,
secrets, store, images, Kea. Det är rätt urval för de funktionerna.

Men ingenting testar de gränser där ett fel blir en säkerhetsincident:

* `roleHasPermission()` — behörighetsmatrisen. Ingen test.
* `verifyCsrfToken()` — ingen test.
* Godkännandegrinden i `boot.cfg.php` / `boot.ipxe.php`: en `pending`-host får
  inte ett bootbart svar. Ingen test.
* `apiVerifyToken()` — `ApiAuthTest.php` finns; kontrollera att den täcker
  okänd token, tom token, och rätt digest med fel roll.

Det är också exakt de gränser som ändras av åtgärderna i säkerhetsgranskningen,
vilket gör testerna till en förutsättning snarare än en efterhandsåtgärd.

### Åtgärd

Tre filer:

* `tests/PermissionTest.php` — går igenom `actionPermission()`-tabellen (S2) och
  kontrollerar att `operator` nekas allt utom `read`/`approve`/`scan`, och att
  `admin` får allt.
* `tests/CsrfTest.php` — token saknas, token fel, token rätt; och att
  `verifyCsrfToken()` returnerar `false` när sessionen är tom.
* `tests/BootGateTest.php` — statusgrinden som en ren funktion. Kräver att
  `$status !== 'approved' && $status !== 'deploying'`-kontrollen bryts ut ur de
  två endpointsen till `lib/bootcfg.php`, vilket också tar bort en dubblering.

---

## Sammanfattning

| Prio | Fynd | Varför nu |
|---|---|---|
| P1 | [C1](#c1), [C2](#c2) | prestanda som växer med estatets storlek; låser bootande hostar |
| P1 | [C15](#c15) | förutsättning för säkerhetsåtgärderna |
| P2 | [C3](#c3), [C4](#c4), [C5](#c5), [C6](#c6) | korrekthetsfällor med känd utlösare |
| P2 | [C7](#c7) | märks vid varje sidladdning på ett estat med flera versioner |
| P3 | [C8](#c8)–[C14](#c14) | städning; ingen brådska, men billigt |
