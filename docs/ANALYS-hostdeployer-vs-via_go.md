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
| DHCP | Kea (ISC), styrd via control-socket (`lib/kea.php`) | Egen DHCPv4 på raw socket (`internal/dhcp`) |
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
- **Kea** som DHCP-motor. En produktionsmässig DHCP-server från ISC, med
  riktig lease-databas, DECLINE-hantering, HA och loggning.

### Bara via_go
- **En binär.** Ingen nginx, ingen PHP-FPM, ingen sudo-regel, inget
  distroberoende. Detta är den enskilt största praktiska skillnaden.
- **DHCP-relay / IP-helper** (`RelayAgentIP`) — binären behöver inte sitta på
  provisioneringsnätet.
- **Device classes med option-lagring** — vendor class (`PXEClient:Arch:00007`,
  `HTTPClient:Arch:00016`, ARM64-varianterna) styr bootmetod, och DHCP-optioner
  löses global → group → host med prioritet. Genuint bra design.
- **Websocket-strömmade loggar** till UI:t.
- **Genererad OpenAPI-spec** (`docs/swagger.json`) och `example-scripts/`.
- **Uppström.** `maxiepax/go-via` är den etablerade VIA-ersättaren i
  VMware-communityn. Rättningar kan gå åt båda håll.

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

**via_go → hostdeployer:** att bygga in en egen DHCP-server med
device-class-hantering och relay-stöd i PHP är inte rimligt. Att bli en binär
är per definition omöjligt. Uppströmslänken går inte att replikera alls.

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

DHCP-motorn — via_go:s egen raw-socket-implementation mot hostdeployers Kea —
är medvetet **inte** viktad i rekommendationen. Den kvarstår som en teknisk
uppgift i via_go (DECLINE hanteras inte, se punkt 4 nedan), inte som ett
argument i valet mellan repona.

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

1. **Porta iLO-upptäckten.** `scripts/ilo_scanner.py` pekas om från
   hostdeployers `/api/v1/hosts` till via_go:s `/v1/hosts`. Behåll den i
   Python först — Redfish-biblioteket finns där och omskrivning i Go är
   onödigt arbete innan flödet är bevisat.
2. **Porta Secure Boot-hanteringen** på samma sätt.
3. **Lägg in VCF-mallen** som ett val i via_go:s kickstart-generator
   (`internal/api/ks.go` har redan `defaultks` som Go-template och
   group-options att hänga valet på).
4. **Stäng DHCP DECLINE-luckan** i `internal/dhcp` mot reservationsmodellen.
   Den gamla implementationen byggde på pools och försvann med dem.
5. **Engångstoken i `ks=`-URL:en.** Båda repona delar ut rotlösenordshashen
   till vem som helst som kan ta hostens IP på provisioneringsnätet. Detta
   är den allvarligaste kvarvarande säkerhetsbristen i båda och står redan
   som "not started" i via_go:s fas 3.
6. **Verifiera mot ESXi 8/9 på nested VM:ar**, en per bootväg (PXE-klass och
   HTTP-Boot-klass), innan hårdvara rörs.

Punkt 1–3 är den halva som gör via_go strikt bättre än hostdeployer på allt.
Efter dem finns ingen anledning att hålla två träd vid liv.

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
