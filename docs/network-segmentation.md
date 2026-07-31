# Nätverkssegmentering — admin, deploy och iLO/iDRAC

hostdeployer rör tre nät, och hör hemma på vart och ett på olika sätt:

| Nät | Vad appliancen gör där | Lyssnare |
|---|---|---|
| **Admin** | dashboard och REST-API över TLS | `443` (och `80` som redirect) |
| **Deploy** | DHCP, TFTP och bootkedjan över HTTP | `67/udp`, `69/udp`, `80` |
| **iLO/iDRAC** | pratar **ut** mot managementkorten | **inga** |

Den viktiga raden är den sista. Trafiken mot iLO/iDRAC initieras av admin-UI:t —
en skanning eller en secure boot-växling — och går utgående till kortens
Redfish-API. Ingenting på appliancen ska lyssna på det nätet.

Och admin-UI:t ska inte vara nåbart på deploy-nätet. Bootkedjan där är
oautentiserad av nödvändighet: klienterna är firmware och sedan en installer,
och ingen av dem kan hålla ett credential. Ett inloggningsformulär på samma
adress är att lägga den enda autentiserade ytan på det enda nät som inte kan
autentisera.

---

## 1. Vad som måste vara nåbart var

### Deploy-nätet — inkommande

| Tjänst | Port | Vad |
|---|---|---|
| Kea DHCPv4 | 67/udp | broadcast; kräver L2-närvaro eller en DHCP-relay (§5) |
| tftpd-hpa | 69/udp | **bara** UEFI-PXE-grenen. Hostar som klarar UEFI HTTP Boot behöver den inte |
| nginx | 80/tcp | bootkedjan, endpoint för endpoint nedan |

| Väg | Anropas av | Steg |
|---|---|---|
| `/ipxe/` | iPXE-firmware | `ipxe.efi`, `boot.ipxe` |
| `/boot.ipxe.php?mac=` | iPXE | genererar bootskriptet, väntar på godkännande |
| `/mboot.efi` | UEFI HTTP Boot-firmware | löser hostens ESXi-version, streamar laddaren |
| `/boot.cfg`, `/boot.cfg.php` | mboot | per-host boot.cfg med `prefix=`, `ks=`, `netdevice=` |
| `/esxi/<version>/…` | mboot | kernel + ~110 moduler |
| `/ks.cfg?mac=&t=` | weasel (installern) | kickstart — kräver boot-token |
| `/progress.php?mac=&t=` | `%firstboot` | förloppsbeacon — kräver boot-token |
| `/deployment_complete.php?mac=&t=` | `%firstboot` | slutcallback — kräver boot-token |

### Admin-nätet — inkommande

| Väg | Port | Vem |
|---|---|---|
| `/admin/` | 443/tcp | driftpersonalens webbläsare |
| `/api/v1/…` | 443/tcp | automation med bearer-token |

### iLO/iDRAC-nätet — bara utgående

| Mål | Port | Vem | När |
|---|---|---|---|
| managementkorten | 443/tcp | `scripts/ilo_scanner.py` | operatören startar en skanning |
| managementkorten | 443/tcp | `scripts/secure_boot_manager.py` | vid godkännande och vid slutförd installation |
| managementkorten | ICMP | `ilo_scanner.py` | probar innan Redfish-anropet |

Inga inkommande. Ingen lyssnare. Se §6 för varför det är värt att hålla på.

### Detaljen som är lätt att missa

Slutcallbacken kommer **inte** från DHCP-leasen. Hosten har vid den tidpunkten
installerat klart, bootat om och rest sitt managementinterface på den adress
operatören angav vid godkännandet. Anropet går till
`{{SERVER_URL}}/admin/deployment_complete.php` — alltså till port 80 på den
adress som står i `webserver.url`, som ska vara **deploy-adressen**.

Är ESXi-hostarnas managementnät samma som deploy-nätet fungerar det direkt.
Delar du dem måste port 80 vara nåbar även från managementsubnätet, annars
fastnar varje host på "deploying" trots att installationen lyckades. Samma sak
gäller `progress.php`-beaconen.

---

## 2. Hur det konfigureras

`deploy/nginx-config.sh` genererar nginx-siten. Varje lyssnare binder en
**explicit adress** — det är hela poängen, och det är därför det är en
generator och inte en statisk fil: med en adress ska bootlyssnaren också
skicka webbläsare vidare till TLS, med två får den inte göra det. Det är en
annan form, inte en annan sträng, och `sed` kan inte lägga till eller ta bort
ett server-block.

### Enbenad installation (som förut)

```bash
sudo ./install.sh --interface ens192 --server-ip 10.1.40.60 \
     --dhcp-range 10.1.40.232-10.1.40.233 --gateway 10.1.40.1 --dns 10.10.10.1
```

Allt binder `10.1.40.60`. Port 80 redirectar `/admin/` till HTTPS.

### Delad installation

```bash
sudo ./install.sh \
     --interface ens192.20 --server-ip 10.20.0.2 \
     --admin-ip 10.10.0.2 --admin-allow 10.10.0.0/24 \
     --dhcp-range 10.20.0.100-10.20.0.200 --gateway 10.20.0.1 --dns 10.20.0.53
```

Vad som händer då:

* Bootkedjan binder `10.20.0.2:80`. Ingen `/admin/`-redirect — den hade
  annonserat var admin-gränssnittet ligger för varje skanner på deploy-nätet.
  Allt utanför bootkedjan svarar 404.
* Dashboarden och API:t binder `10.10.0.2:443`, med `allow 10.10.0.0/24; deny all;`
  som andra lås. Bindningen är det som gör att paketen aldrig kommer fram;
  `allow`/`deny` är det som gäller om bindningen någon gång vidgas eller om
  trafiken routas dit från ett annat nät.
* `10.10.0.2:80` finns bara för att skicka en operatör som skrev `http://`
  vidare till TLS.
* TFTP binds till `10.20.0.2:69` i stället för Debians `:69`, som är alla
  adresser.
* Certifikatets SAN får **admin**-adressen, eftersom det är den webbläsare
  ansluter till.
* `webserver.ip` och `webserver.url` i `global_config.json` fortsätter vara
  **deploy**-adressen. Det är den som blir `prefix=`, `ks=` och `{{SERVER_URL}}`
  i varje kickstart. Instinkten i en delad uppställning är att fylla i
  adminadressen där, och då slutar varje host kunna hämta sin kickstart.

Verifieringssteget i `install.sh` kontrollerar att admin-gränssnittet **inte**
svarar på deploy-adressen när adresserna skiljer sig. En lyssnare som tyst
binder allt ser identisk ut från adminnätet, och är hela fyndet.

### Saker att veta

* **nginx startar inte om adressen saknas.** Med en explicit adress i `listen`
  failar starten om interfacet ännu inte är uppe. `install.sh` kontrollerar att
  adressen finns på maskinen innan den skriver konfigurationen. För omstarter:

  ```ini
  # /etc/systemd/system/nginx.service.d/10-wait-for-network.conf
  [Unit]
  After=network-online.target
  Wants=network-online.target
  ```
* **IPv4 only.** Den statiska filen som generatorn ersatte hade också
  `listen [::]:80` och `listen [::]:443`, vilket band varje IPv6-adress på
  maskinen — inklusive på nät admin-gränssnittet inte har någon anledning att
  svara på. Kea här är DHCPv4 och alla adresser appliancen hanterar är v4.

---

## 3. Brandvägg på appliancen

Bindningarna är den primära kontrollen. nftables är den som fortsätter gälla när
någon lägger till en tjänst och glömmer binda den — och den som gör iLO-benet
försvarbart, se §6.

```nft
# /etc/nftables.conf
table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;

        ct state established,related accept
        iif lo accept

        # Deploy: DHCP, TFTP och bootkedjan. Inget mer.
        iifname "ens192.20" udp dport { 67, 69 } accept
        iifname "ens192.20" tcp dport 80 accept

        # Admin: dashboard och API, bara från driftnätet.
        iifname "ens192.10" ip saddr 10.10.0.0/24 tcp dport { 22, 80, 443 } accept

        # iLO/iDRAC: ingenting inkommande. Svaren täcks av
        # established,related ovan.

        ip protocol icmp accept
    }

    chain output {
        type filter hook output priority 0; policy accept;

        # Mot iLO-nätet: bara Redfish och den probe skannern gör först.
        # Resten dröppas, så en komprometterad appliance inte kan använda
        # benet till något annat än det den fick det för.
        oifname "ens192.30" tcp dport 443 accept
        oifname "ens192.30" ip protocol icmp accept
        oifname "ens192.30" drop
    }
}
```

Anpassa interfacenamn och nät. Poängen är att 443 aldrig accepteras på
deploy-benet, att ingenting accepteras inkommande på iLO-benet, och att
utgående mot iLO är begränsat till det som faktiskt behövs.

---

## 4. Vilken adress hör hemma var

| Inställning | Fil | Värde |
|---|---|---|
| `webserver.ip`, `webserver.url` | `config/global_config.json` | **deploy** |
| `next-server`, `boot-file-name` | `/etc/kea/kea-dhcp4.conf` | **deploy** |
| `interfaces-config.interfaces` | `/etc/kea/kea-dhcp4.conf` | **deploy**-interfacet |
| `TFTP_ADDRESS` | `/etc/default/tftpd-hpa` | **deploy**:69 |
| `listen …:80` (bootkedjan) | nginx-siten | **deploy** |
| `listen …:443` | nginx-siten | **admin** |
| certifikatets SAN | `/etc/ssl/autodeploy/server.crt` | **admin** + FQDN |
| `ilo.scan_range_start/end` | `config/global_config.json` | **iLO**-intervallet |
| `AUTODEPLOY_API_URL` | php-fpm-poolen | `https://127.0.0.1/api/v1` — hjälpskripten kör lokalt |

---

## 5. Kea på ett VLAN-subinterface

### L2-närvaro (det normala)

```bash
sudo /srv/autodeploy/deploy/kea-config.sh \
     --interface ens192.20 --server-ip 10.20.0.2 \
     --subnet 10.20.0.0/24 --pool "10.20.0.100 - 10.20.0.200" \
     --gateway 10.20.0.1 --dns 10.20.0.53 \
     > /etc/kea/kea-dhcp4.conf
```

Subinterfacenamnet går rakt in i `interfaces-config`. Lägg till en drop-in så
Kea inte startar före interfacet:

```ini
# /etc/systemd/system/kea-dhcp4-server.service.d/20-wait-for-network.conf
[Unit]
After=network-online.target
Wants=network-online.target

[Service]
Restart=on-failure
RestartSec=5
```

Subnätet är bundet till klassen `PXE-CLIENTS`, så Kea besvarar bara maskiner som
nätbootar. Utan det svarar den på varje DHCPDISCOVER i broadcastdomänen — på ett
delat nät är det en rogue DHCP-server.

### DHCP-relay

Har appliancen inget ben i hostarnas VLAN behöver konfigurationen `relay`:

```json
"interfaces-config": { "interfaces": [ "ens192.10" ] },
"subnet4": [{
    "subnet": "10.20.0.0/24",
    "relay": { "ip-addresses": [ "10.20.0.1" ] },
    "client-class": "PXE-CLIENTS"
}]
```

`lib/kea.php` bevarar `relay`, `client-class` och `require-client-classes` när
DHCP ändras från admin-UI:t, så en nätverksändring där tar inte bort dem.

---

## 6. iLO/iDRAC — eget ben eller routad väg?

Frågan är om appliancen ska ha ett interface direkt på managementnätet eller nå
det routat genom en brandväggsöppning.

**Ett interface är en bredare rättighet än en brandväggsregel.** En regel
`deploy-host → iLO-nät:443` är smal och granskningsbar. Ett ben på VLAN:et
betyder att appliancen är *i* broadcastdomänen: den kan nås av allt som finns
där, den kan ARP-spoofa, och blir den komprometterad har den oinskränkt L2-
åtkomst till varje managementkort — inte bara till port 443. Ett managementkort
är out-of-band root på en fysisk server: strömkontroll, virtuell media, konsol.
Det är estatets känsligaste nät.

Så: routad väg med en smal regel är det tekniskt riktiga svaret.

**Men** — och det här är det som avgör i praktiken — en brandväggsprocess som
är tillräckligt trög blir en säkerhetsrisk i sig. Regler som tar veckor att få
igenom blir breda "för säkerhets skull", och undantag som skulle ha tagits bort
blir kvar. Att välja ett eget ben för att slippa den processen är ett försvarbart
beslut, förutsatt att man återskapar den smala regeln lokalt.

Gör du det så:

1. **nftables är inte valfritt.** Reglerna i §3 — inget inkommande på iLO-benet,
   utgående begränsat till 443 och ICMP — är det som gör benet till en
   brandväggsregel i stället för en öppen dörr. Svagare, eftersom det upprätthålls
   av det som ska skyddas, men under din kontroll i stället för
   brandväggsteamets.

2. **`verify=False` väger tyngre nu.** `ilo_scanner.py` och
   `secure_boot_manager.py` gör sina Redfish-anrop utan certifikatvalidering
   (`scripts/ilo_scanner.py:113` m.fl.). På en routad väg krävs en position i
   nätet för att utnyttja det; på ett direktanslutet L2-segment räcker
   ARP-spoofing, och det som fångas är iLO-lösenordet. Att ta bort `verify=False`
   var lågprioriterat när benet var routat — med ett eget ben är det inte det.

3. **S8 väger också tyngre.** `local-helpers`-tokenen har admin-roll och kan
   hämta iLO-credentials över API:t. Kodexekvering som `www-data` på en appliance
   med ett iLO-ben ger både lösenordet och vägen dit.

### Det tredje alternativet: inget iLO-ben alls

Värt att pröva innan man bestämmer sig. iLO-integrationen är **valfri** i den
här kodbasen:

* **Secure boot-hanteringen** styrs av `security.secure_boot_enabled`, som är
  `false` som standard. Använder du den inte behövs `secure_boot_manager.py`
  aldrig.
* **Hårdvaruupptäckten** görs redan av bootkedjan: autoregistrering lägger in
  varje host som nätbootar, med MAC och serienummer. `ilo_scanner.py` ger
  utöver det modell, BIOS-version och iLO-adress — trevligt, inte nödvändigt.

Kör du utan secure boot-automatik och låter hostarna registrera sig själva
behöver appliancen aldrig prata med managementkorten, och benet kan strykas.
Det är den enda varianten där frågan inte finns.

### Om du behåller benet

En sak till som kostar lite och ger mycket: **ett eget iLO-konto med enbart
läsrättigheter för skanningen.** `ilo_scanner.py` läser bara; det är
`secure_boot_manager.py` som skriver. Kodbasen har ett enda `ilo`-credential för
båda, så det kräver en ändring — men ett läsande konto i
`config/credentials.json` är ett mycket mindre problem om filen någonsin läcker
än ett som kan boota om estatet.

---

## 7. vSphere-sidan

Utanför det här systemets ansvar. Om appliancens VM har ett vNIC på en
trunkad portgroup med subinterface i gästen, eller flera vNIC på var sin
portgroup med fast VLAN-ID, spelar ingen roll för hostdeployer — den konfigurationen
görs innan systemet installeras och syns här bara som interfacenamn.

Det enda som är värt att säga: fler ben betyder fler adresser en lyssnare kan
råka binda. Det är därför per-adress-bindningen i §2 betyder mer i en
flerbenad uppställning, inte mindre.
