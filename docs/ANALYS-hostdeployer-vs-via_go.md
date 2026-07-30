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
startar fem systemd-enheter. Varje uppgradering rör alla dessa. via_go:s
motsvarighet är `scp` av en fil, eller `docker compose up`.

För en VIA — ett verktyg som ska kunna ställas ner i en kundmiljö, på ett
jumphost, i ett labb — är det skillnaden mellan en produkt och en installation.

### Kodbasernas mognad

hostdeployer-repots historik börjar **2026-07-29** med "Add files via upload":
ett internt verktyg som checkades in för två dagar sedan och sedan sanerades
hårt. `docs/CODE-REVIEW.md` listar vad som hittades — godtycklig filskrivning
→ RCE i `templates.php`, tre funktioner som anropades men aldrig definierades
(hela Hardware Scan-fliken död), rotlösenord i klartext, race conditions.
Allt är åtgärdat, men kodbasen har precis blivit trovärdig.

via_go bär fem års uppströmshistorik med fältanvändning bakom sig, och gick
igenom en jämförbar sanering (28 → 0 sårbarheter, 51 → 0 lint-fynd, SQL-
injektion i sök, `UpdateUser` som förstörde lösenordshashen, DHCP-panik).

Båda är i dag rimligt trovärdiga. Skillnaden är hur mycket verklig drift
koden bakom dem har sett.

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

**Satsa på `via_go`. Behåll hostdeployer som källa för tre saker och lägg ner
den därefter.**

Motivering, kortast möjligt: via_go är redan en produkt — en binär, med
API, UI, tester, CI, paketering och en uppström. hostdeployer är efter de
senaste veckornas arbete en PHP-reimplementation av samma sak ovanpå en
femtjänsters driftstack, och dess egna särdrag är de billigaste sakerna i
hela jämförelsen att flytta.

### Föreslagen ordning

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

## 5. Det öppna beslutet: licensen

Licensfrågan är inte avgjord, och den är den enda som kan vända svaret. Därför
står båda spåren beskrivna här.

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

Frågan är inte teknisk. Den är: ska det här kunna säljas utan att källkoden
följer med? Tills den är besvarad är det billigaste draget att **inte** börja
porta något åt något håll — punkt 5 (engångstoken) och punkt 6 (verifiering
mot ESXi 8/9 på nested VM:ar) är arbete som behövs i båda spåren och kan
påbörjas direkt i vilket repo som helst.

---

## Verifiering av analysen

```bash
cd via_go && go build ./... && go test ./... -cover    # allt grönt
cd hostdeployer && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse
```

Underlag som lästes: `via_go/COMPARISON.md`, `hostdeployer/docs/CODE-REVIEW.md`,
`hostdeployer/docs/PLAN-via-go-port.md`, samt bootkedjan, DHCP-, kickstart-,
store- och auth-lagren i båda träden.
