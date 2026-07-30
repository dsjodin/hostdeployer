# Analys: hostdeployer vs via_go — vilket repo är värt att satsa på?

## Context

Du har två repon som löser exakt samma problem — automatiserad bare-metal-
provisionering av VMware ESXi — med rakt motsatta arkitekturer. Frågan är
vilket som är värt att lägga fortsatt tid på.

Analysen bygger på egen genomgång av båda träden: `go build` + `go test` kört
mot via_go, kodläsning av bootkedjan, DHCP-hanteringen, kickstart-generatorn,
lagringslagret och auth i båda, samt CI-konfiguration, licens och git-historik.

---

## 1. Gör de samma sak?

**Ja.** Båda implementerar samma kedja, ände till ände:

```
DHCP (option 60/66/67, vendor class)
  → UEFI HTTP Boot eller PXE/TFTP
    → mboot.efi + omskriven boot.cfg (prefix=, kernelopt= med ks=)
      → ks.cfg genererad per host (IP, netmask, gateway, VLAN, FQDN, rootpw)
        → %firstboot (NTP, syslog, SSH, certifikat)
          → callback till servern → 100 %
```

Plus inventarium i SQLite, webb-UI, REST-API, krypterade ESXi-rootlösenord,
ISO-uppladdning med hashverifiering och progressrapportering längs bootkedjan.

Det är inte en slump att de ligger så nära varandra. `hostdeployer/docs/
PLAN-via-go-port.md` visar att hostdeployer i sju faser medvetet portat
via_go:s styrkor — store-lager, REST-API, kryptering, SQLite, progress,
ISO-uppladdning, omskriven boot.cfg. Funktionellt är gapet i stort sett stängt.

**Den kvarvarande skillnaden är arkitektonisk, inte funktionell.**

| | hostdeployer | via_go |
|---|---|---|
| Modell | **Orkestrerare** — konfigurerar andras tjänster | **Appliance** — är tjänsterna |
| Språk | PHP 8.1+ / Python 3 | Go 1.25 |
| DHCP | Kea (ISC), styrd via control-socket (`lib/kea.php`) | Egen DHCPv4 på raw socket (`internal/dhcp`) — **en skuld, se 2b** |
| Webbserver | nginx + PHP-FPM | inbyggd (gin), två lyssnare (:80 boot, :443 API/UI) |
| TFTP | tftpd-hpa | inbyggd (`internal/tftp`) |
| Distribution | `install.sh` (885 rader), Debian 13, systemd × 5 | en statisk binär, eller `docker compose up` |
| Datalager | SQLite (hosts) + JSON-filer (config, credentials) | SQLite via GORM, allt |
| UI | Bootstrap 5, serverrenderad PHP | Next.js 15 / React 19, `go:embed`:ad |
| API | REST, bearer-token (`www/api.php`) | REST, session + basic auth, OpenAPI/Swagger genererad |
| Kodrader | ~12 400 PHP + ~950 Python | ~11 400 Go + UI |
| Tester | 182 PHPUnit-metoder, PHPStan lvl 5 | 122 testfunktioner, coverage mätt per paket |
| CI | php-lint, phpunit, phpstan, shellcheck, ruff | gofmt, vet, race, golangci-lint, govulncheck, UI-build |
| Licens | **proprietary** | **GPL-3.0** (fork av maxiepax/go-via) |

Verifierat: `go build ./...` grönt, samtliga via_go-paket gröna
(`internal/boot` och `internal/netutil` 100 %, `auth` 95 %, `server` 77 %,
`model` 79 %, `secrets` 80 %, `dhcp` 47 %, `api` 33 %).

---

## 2. Vad bara det ena har

### Bara hostdeployer
- **`scripts/ilo_scanner.py`** — skannar ett subnät över iLO/Redfish, hittar
  servrar, hämtar MAC och serienummer och fyller inventariet. Du behöver
  alltså inte veta MAC-adresserna i förväg. via_go har ingenting motsvarande.
- **`scripts/secure_boot_manager.py`** — läser och sätter Secure Boot-status
  out-of-band via Redfish.
- **`templates/kickstart_template_vcf.cfg`** — VCF-specifik kickstart som
  lämnar vMotion och övrig nätverkskonfiguration åt VCF.
- **Kea** som DHCP-motor, med klassificeringen redan skriven och körande. Se
  avsnitt 2b — detta är hostdeployers tyngsta enskilda fördel.

### Bara via_go
- **En binär.** Ingen nginx, ingen PHP-FPM, ingen sudo-regel, inget
  distroberoende. Detta är den enskilt största praktiska skillnaden.
- **Device classes med option-lagring** — DHCP-optioner löses global → group →
  host med prioritet (`internal/model/option.go`). Prioritetsmodellen är bra;
  DHCP-servern under den är det inte (avsnitt 2b).
- **Websocket-strömmade loggar** till UI:t.
- **Genererad OpenAPI-spec** (`docs/swagger.json`) och `example-scripts/`.
- **Uppström.** `maxiepax/go-via` är den etablerade VIA-ersättaren i
  VMware-communityn. Rättningar kan gå åt båda håll.

---

## 2b. Den egna DHCP-servern är en skuld, inte en tillgång

Första versionen av den här analysen räknade via_go:s inbyggda DHCP-server som
en styrka. **Det var fel**, och rättas här.

En egenbyggd DHCP-server betyder att man äger ett nätverksprotokoll själv, och
står ensam när det slutar fungera. Konkret, i den här kodbasen:

**Omfattningen.** `internal/dhcp/dhcpd.go` är 637 rader som gör
DISCOVER/REQUEST-hantering, option-kodning per RFC 2132, paketbygge och
broadcast-vs-unicast-beslut, på en rå AF_PACKET-socket
(`raw.ListenPacket`, kräver `CAP_NET_RAW`). Testtäckningen är 47 %.

**Beroendena är övergivna.**

| Beroende | Version | Status |
|---|---|---|
| `github.com/mdlayher/raw` | `v0.0.0-20191009` | **Deprecated.** Författaren hänvisar till `mdlayher/packet` |
| `github.com/google/gopacket` | `v1.1.19` | Underhållet flyttat till community-forken `gopacket/gopacket` |

Det är alltså inte framtida underhållsskuld — den är förfallen idag.

**Kea gör redan samma sak, bättre.** hostdeployers `dhcp/kea-dhcp4.conf`
klassificerar på exakt samma signaler:

| via_go `internal/dhcp` | Kea client-class |
|---|---|
| `HTTPClient:Arch:00016` → HTTP boot | `option[60] == 'HTTPClient'` → `boot-file-name` |
| `PXEClient:Arch:00007/00011` → TFTP | `option[93] == 0x0007/0x0009/0x000b` → `next-server` |
| *saknas* | `option[77] == 'iPXE'` → iPXE-kedjan |

Utöver det ger Kea lease-databas, DECLINE-hantering, HA, strukturerad loggning
och ISC bakom sig. `lib/kea.php` (350 rader) driver redan `config-set` över
control-socketen, så integrationsmönstret är bevisat.

Det innebär också att **DHCP-relay / IP-helper inte är en via_go-styrka** —
Kea hanterar relayad DHCP nativt och mer komplett.

**Följden för rekommendationen.** Den vänder inte, eftersom `internal/dhcp` är
ett *isolerat* paket: det läser samma store och skriver option 67, och är inte
invävt i resten. Men två saker ändras:

1. Argumentet "en binär, inga beroenden" försvagas. Med Kea blir via_go binär +
   Kea = två komponenter, mot hostdeployers fem (nginx, PHP-FPM, Kea,
   tftpd-hpa, sudo-regel). Fortfarande bättre, men 5:2 och inte 5:1.
2. Att riva ut DHCP-servern flyttar först i ordningen — se avsnitt 4.

---

## 2c. Blir Web UI:t lidande av en Go-monolit?

En rimlig oro, men premissen håller inte för den här kodbasen: **UI:t ligger
inte i monoliten.**

`ui/` är ett fristående Next.js-projekt på 1 723 rader med tre
runtime-beroenden (`next`, `react`, `react-dom`). All serverkontakt går genom
en enda wrapper — `ui/src/lib/api.js`, ett `fetch(\`/v1${path}\`)`. Ingen
Go-kod känner till UI:t; inget i UI:t känner till Go. `go:embed` sker vid
*build* (`webui/embed.go`), inte vid utveckling: `next dev` körs mot API:t med
hot reload, och binären är inte i loopen.

Kopplingen är alltså lösare än i hostdeployer, där render och logik är
sammanvuxna:

| | rader | innehåll |
|---|---|---|
| `www/hosts.php` | 707 | en `renderHostsContent()` som emitterar HTML, med inline `<script>` från rad 651 |
| `www/templates.php` | 1 772 | sökvägsvalidering + fil-I/O + backup-logik + HTML + inline JS i samma fil |

`docs/CODE-REVIEW.md` noterar det själv: *"templates.php är 1700 rader —
render- och åtgärdslogiken bör delas upp."*

**Men embed-modellen har verkliga friktioner:**

1. **`output: "export"` är ett tak.** Statisk export betyder ingen SSR, inga
   server actions, inga Next.js API-routes, ingen ISR.
2. **Papperssnitten är av en särskild sort.** `http.FileServer` har ingen
   `.html`-fallback, så en omladdning på annan sida än roten gav 404 tills
   `trailingSlash: true` sattes — och klick-navigering dolde felet.
3. **Två toolchains.** Node och Go i samma CI, plus en placeholder-`index.html`
   incheckad enbart för att `go build` ska fungera utan Node.

**Nödutgången:** embedding är ett paketeringsval, inte ett arkitekturkrav.
Serveras UI:t separat blir binären rent API. Det kostar en-artefakt-egenskapen
men är en öppen dörr, inte en återvändsgränd.

---

## 2d. Underhållsfrågan: vem patchar?

Den tyngsta invändningen mot via_go, och den generaliserar 2b.

`install.sh` installerar 15 paket som **någon annan underhåller**. Kea, nginx,
tftpd-hpa och PHP-FPM får CVE-rättningar genom Debians säkerhetsuppdateringar
utan att någon här behöver göra något. Det via_go implementerar själv får inga:

| via_go implementerar själv | rader | vilar på |
|---|---|---|
| `internal/dhcp` | 637 | `mdlayher/raw` (deprecated, 2019), `gopacket` v1.1.19 |
| `internal/tftp` | 232 | `pin/tftp` — pseudoversion från 2021, ingen tagg |
| `internal/auth` | 449 | egen sessionshantering |
| `internal/crypto` | 150 | egen CA- och certifikatgenerering |

Det finns ingen `apt upgrade` som rättar en bugg i via_go:s TFTP-server.

**`govulncheck: 0` är delvis en artefakt.** Verktyget rapporterar mot
publicerade advisories. Mot övergivna bibliotek som ingen längre skriver
advisories om finns inget att rapportera. Noll fynd är där frånvaro av
bevakning, inte bevis på sundhet.

**Vad som ändå står kvar.** Av hostdeployers fem tjänster utför bara två
arbete. Kea och tftpd-hpa gör något; **nginx och PHP-FPM gör inget
funktionellt** — de är driftskostnaden för att språket är PHP. Go:s
HTTP-server sitter in-process på `gin`, som är underhållet och aktivt taggat.

Jämförelsen är alltså inte 1:5. Med DHCP och TFTP delegerade blir den
**binär + Kea + tftpd-hpa (3)** mot **nginx + PHP-FPM + Kea + tftpd-hpa (4)**,
och skillnaden är precis PHP-runtimen. Det är en smal marginal, och den
motiverar inte en port på egen hand.

---

## 3. Bedömning

### Den avgörande asymmetrin

Räkna kostnaden för att flytta det unika åt vardera hållet:

**hostdeployer → via_go:** iLO-skannern och Secure Boot-hanteraren är ~800
rader Python som pratar Redfish över HTTP och skriver till ett REST-API. De är
redan frikopplade — fas 2 i port-planen tvingade dem bort från direkta
filläsningar och över på API:t (`scripts/autodeploy_api.py`). De kan peka om
mot via_go:s `/v1/hosts` med i storleksordningen en dags arbete.
VCF-mallen är en textfil.

**via_go → hostdeployer:** att bli en binär är per definition omöjligt.
Uppströmslänken går inte att replikera alls. Go:s typsäkerhet, race-detektorn
och `govulncheck` har ingen motsvarighet i PHP-verktygskedjan.

(Den egna DHCP-servern räknas medvetet **inte** som något att flytta åt något
håll — den ska bort, se avsnitt 2b.)

**Det unika i hostdeployer är billigt att flytta. Det unika i via_go är det
inte.** Det är hela argumentet i en mening.

### Driftbilden

`install.sh` är 885 rader och installerar 15 paket, konfigurerar PHP-FPM,
nginx med certifikat, Kea med control-agent, tftpd-hpa och en sudo-regel, och
startar fem systemd-enheter. via_go:s motsvarighet är `scp` av en fil, eller
`docker compose up`.

För en VIA — ett verktyg som ska kunna ställas ner i en kundmiljö, på ett
jumphost, i ett labb — är färre rörliga delar vid utrullning en verklig fördel.

**Men det är bara halva bilden.** Antalet tjänster mäter installationens
komplexitet, inte förvaltningens. Där går pilen åt andra hållet: de 15 paketen
patchas av Debian, medan varje rad i via_go är egen. Se avsnitt 2d — de två
avsnitten ska läsas tillsammans, och de tar i stor utsträckning ut varandra.

### Kodbasernas mognad

**En rättelse.** En tidigare version av det här avsnittet läste hostdeployers
git-historik — som börjar 2026-07-29 med "Add files via upload" — som att
verktyget vore två dagar gammalt. Det är en ogiltig slutledning: incheckningen
säger när koden lades på GitHub, ingenting om hur länge den utvecklats eller
körts. hostdeployer kan ha gått i produktion i åratal. Argumentet är struket.

Vad koden *faktiskt* bär bevis för är smalare, och gäller enskilda funktioner
snarare än produkten. Ur `docs/CODE-REVIEW.md`:

- **`processScanActions()` saknades helt.** Hela Hardware Scan-fliken gav
  `Fatal error` och en vit sida. Den som använt fliken hade märkt det direkt.
- **`admin_ui.php` laddade aldrig Bootstrap CSS**, trots att hela dashboarden
  är byggd på Bootstrap 5-klasser. Syns vid första sidladdning.
- **`generate_kickstart.php` hade grenarna i fel ordning**, så "väntar på
  godkännande" var oåtkomlig — väntande servrar fick fel + `reboot` och
  hamnade i en ominstallationsloop.

Slutsatsen som håller är alltså inte "koden är omogen", utan: **de här
funktionerna var bevisligen inte i bruk**. Kärnvägen — DHCP, boot, kickstart —
kan mycket väl vara körd hårt i skarp drift; det finns inget i koden som säger
emot det.

via_go gick igenom en jämförbar sanering (28 → 0 sårbarheter, 51 → 0
lint-fynd, SQL-injektion i sök, `UpdateUser` som förstörde lösenordshashen,
DHCP-panik på blank gateway). Samma slag av fynd, i samma storleksordning.

**Mognad är därmed inte en skiljelinje mellan repona**, och väger inte i
rekommendationen. Det som väger är flyttkostnaden ovan.

### Vad som talar emot via_go

Var ärlig om detta — det är inte ett enkelriktat val:

1. **GPL-3.0 är viral.** Ska det här någonsin ligga inuti en sluten produkt är
   via_go uteslutet, och hostdeployer (proprietary) är svaret. Detta är den
   enda faktorn som ensam kan vända rekommendationen, och den är **inte
   avgjord** — se avsnitt 5.
2. **Ingen av dem är verifierad mot riktig hårdvara nyligen.** Bootkedjan i
   båda är testad mot fixtures, inte mot firmware, och kickstart-logiken i
   via_go härstammar från ESXi 6.7/7.0-eran. Den risken följer med oavsett val.

3. **Den egna DHCP-servern** — 637 rader nätverksprotokoll på två övergivna
   bibliotek, som Kea redan gör bättre. Se avsnitt 2b. Detta är en verklig
   kostnad i via_go-spåret, och den första som ska betalas av.

---

## 4. Rekommendation

**Svag preferens för `via_go` — men marginalen är smal, och avgörs inte av
koden.**

Första utkastet av den här analysen rekommenderade via_go tydligt, på tre
argument: egen DHCP som styrka, en-binär-fördelen, och git-historiken som
mognadsmått. Två av dem har fallit helt (2b, 3 *Kodbasernas mognad*) och det
tredje har halverats (2d). Det vore oärligt att behålla slutsatsens ursprungliga
säkerhet när underlaget krympt så mycket.

**Vad som faktiskt återstår på vardera sidan:**

| via_go | hostdeployer |
|---|---|
| Typsäkerhet, race-detektor, mätt täckning | Infrastrukturen underhålls av Debian (2d) |
| Genererad OpenAPI-spec | Kea-integrationen fungerar redan |
| Uppströmslänk till `maxiepax/go-via` | iLO/Redfish, Secure Boot, VCF-mall |
| UI frikopplat från applikationen (2c) | Fri licens (proprietary) |
| Färre rörliga delar vid utrullning | Ingen port behövs — arbetet är redan gjort |

Den sista raden till höger väger tungt och saknades i första utkastet:
**hostdeployer kräver ingen migration.** via_go-spåret kostar en DHCP-omskrivning
plus tre portar innan det ens är i kapp.

Argumentet som fortfarande håller för via_go är flyttkostnads-asymmetrin —
hostdeployers särdrag är billiga att flytta, via_go:s är det inte. Men det är
ett argument om *riktning*, inte om nödvändighet, och det räcker inte ensamt för
att motivera att kasta bort ett fungerande system.

**Det som avgör är inte koden**, utan vem som ska förvalta det här och vad de är
bekväma att driva. Se avsnitt 5.

### Föreslagen ordning (om via_go väljs)

1. **Riv `internal/dhcp`, sätt Kea under istället.** Porta `lib/kea.php` till
   ett Go-paket som driver Kea:s control-socket (`config-set`,
   `reservation-add`), och flytta klassificeringen till Kea client-classes med
   `dhcp/kea-dhcp4.conf` som förlaga. Ett drag som tar bort 637 rader
   protokollkod, två övergivna beroenden, DECLINE-luckan och kravet på
   `CAP_NET_RAW`. Behåll option-prioritetsmodellen i
   `internal/model/option.go` — den är bra, det är transporten under som ska bort.
   Detta först, eftersom allt annat bygger ovanpå en DHCP-väg som fungerar.
2. **Porta iLO-upptäckten.** `scripts/ilo_scanner.py` pekas om från
   hostdeployers `/api/v1/hosts` till via_go:s `/v1/hosts`. Behåll den i
   Python först — Redfish-biblioteket finns där och omskrivning i Go är
   onödigt arbete innan flödet är bevisat.
3. **Porta Secure Boot-hanteringen** på samma sätt.
4. **Lägg in VCF-mallen** som ett val i via_go:s kickstart-generator
   (`internal/api/ks.go` har redan `defaultks` som Go-template och
   group-options att hänga valet på).
5. **Engångstoken i `ks=`-URL:en.** Båda repona delar ut rotlösenordshashen
   till vem som helst som kan ta hostens IP på provisioneringsnätet. Detta
   är den allvarligaste kvarvarande säkerhetsbristen i båda och står redan
   som "not started" i via_go:s fas 3.
6. **Verifiera mot ESXi 8/9 på nested VM:ar**, en per bootväg (PXE-klass och
   HTTP-Boot-klass), innan hårdvara rörs.

Punkt 1 betalar av via_go:s största tekniska skuld. Punkt 2–4 är den halva som
gör via_go strikt bättre än hostdeployer på allt. Efter dem finns ingen
anledning att hålla två träd vid liv.

---

## 5. De öppna besluten

Två frågor avgör valet, och ingen av dem går att svara på ur koden.

### 5.0 Vem förvaltar, och vad är de bekväma att driva?

Den viktigaste, och den som avsnitt 2d gör oundviklig. De två repona optimerar
för olika saker:

- **hostdeployer: minimera vad du äger.** Delegera till underhållen
  infrastruktur. Pris: fem tjänster att orkestrera, ett distroberoende, PHP.
- **via_go: minimera vad du rullar ut.** Äg mer av stacken. Pris: du underhåller
  en DHCP- och en TFTP-server själv.

Är svaret "jag kör Debian, jag har `unattended-upgrades`, och jag litar mer på
ISC:s DHCP-server än på min egen" — då är **hostdeployers arkitektur rätt**, och
rekommendationen i avsnitt 4 ska vändas. Det är ett fullt försvarbart svar.

Är svaret "jag vill ha en artefakt att rulla ut och en typad kodbas att ändra i
utan att vara rädd" — då är via_go rätt.

Den kompromiss som finns: kör via_go-spåret men ta 2d på allvar — delegera
**både** DHCP och TFTP till Kea respektive tftpd-hpa. Då blir via_go
"en Go-applikation ovanpå underhållen infrastruktur", vilket är hostdeployers
filosofi med ett bättre applikationslager. Det är den enda varianten där båda
invändningarna besvaras samtidigt.

### 5.1 Licensen

Licensfrågan är inte avgjord, och den kan ensam vända svaret. Därför står båda
spåren beskrivna här.

### Spår A — internt eller öppet (GPL-3.0 är OK) → **via_go**

Rekommendationen i avsnitt 4 gäller rakt av. Kör punkt 1–6 i ordning.
Bonusen är att uppströms `maxiepax/go-via` blir en tvåvägsgata: rättningar
kan skickas tillbaka, och forken ärver communityn kring den etablerade
VIA-ersättaren.

### Spår B — sluten produkt (GPL blockerar) → **hostdeployer**

Då är via_go uteslutet som kodbas, och svaret blir det motsatta:

- Bygg vidare på hostdeployer och hämta hem via_go:s **idéer, inte kod** —
  framför allt device class-modellen där DHCP-optioner löses global → group →
  host med prioritet. Skriv den från specifikationen (RFC 2132 + vendor
  class-strängarna), inte från via_go:s källa, så att härledningen är ren.
- Räkna med att driftstacken förblir verktygets största svaghet. Paketera den
  som en container eller en OVA så att en installation blir en artefakt
  istället för 885 rader `install.sh` mot fem systemd-enheter.
- Ta med samma säkerhetsluckor som via_go har kvar: engångstoken i `ks=`-URL:en
  (punkt 5 ovan gäller båda), och rollkontroll per åtgärd — `hasPermission()`
  finns i `lib/auth.php` men används bara på ett ställe
  (`www/host_status.php`), så en `operator` kan fortfarande allt en `admin` kan.

### Hur beslutet tas

Ingen av de två frågorna är teknisk. Den ena är: ska det här kunna säljas utan
att källkoden följer med? Den andra: vill den som förvaltar systemet äga en
DHCP-server, eller delegera den?

Tills båda är besvarade är det billigaste draget att **inte** börja porta något
åt något håll. Två uppgifter behövs i båda spåren och kan påbörjas direkt i
vilket repo som helst:

- **Engångstoken i `ks=`-URL:en** — rotlösenordshashen delas i dag ut till vem
  som helst som kan ta hostens IP på provisioneringsnätet. Gäller båda.
- **Verifiering mot ESXi 8/9 på nested VM:ar**, en per bootväg. Ingen av dem är
  körd mot riktig firmware.

Det är också de två mest värdefulla sakerna att göra oavsett hur valet faller.

---

## Verifiering av analysen

```bash
cd via_go && go build ./... && go test ./... -cover    # allt grönt
cd hostdeployer && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse
```

Underlag som lästes: `via_go/COMPARISON.md`, `hostdeployer/docs/CODE-REVIEW.md`,
`hostdeployer/docs/PLAN-via-go-port.md`, samt bootkedjan, DHCP-, kickstart-,
store- och auth-lagren i båda träden.
