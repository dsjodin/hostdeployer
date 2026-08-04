# Nätverkssegmentering — tre nät, tre NIC

Deployern sitter på **tre** nät, och att hålla isär dem är hela poängen:

| Nät | NIC | Riktning | Vad |
|---|---|---|---|
| **admin** | 1 | inkommande | Admin-UI och REST-API. Lyssnar här och ingen annanstans. |
| **iLO/iDRAC** | 2 | utgående | Redfish. Ingenting lyssnar; scannern ringer ut. |
| **ESXi mgmt/deploy** | 3 | inkommande | DHCP, bootkedjan, ESXi-media, kickstart. |

Admin-UI:t når varken iLO-nätet eller ESXi-mgmt-nätet, och tvärtom. Skälet är
konkret: dashboarden håller iLO-administratörskontot och ESXi-root-lösenordet
för hela beståndet, och en maskin på deploy-nätet kör per definition kod som
ingen granskat än — en ESXi-image, en kickstart, det installern hämtar.

DHCP är broadcast och måste ligga i samma L2-domän som maskinerna som ska
installeras, så NIC 3 är den som verkligen binds. NIC 2 behöver bara en route.

Det här dokumentet beskriver vad som måste vara nåbart var.

---

## 1. Vilka endpoints hostarna behöver

Bootkedjan är separerad i `nginx.conf`: port 80 på deploy-adressen innehåller
exakt det firmware behöver, port 443 på admin-adressen innehåller admin-UI:t och
REST-API:t. `install.sh` substituerar `DEPLOY_IP` och `ADMIN_IP` när sajten
skrivs, och avbryter om en platshållare blir kvar — en lyssnare som föll
tillbaka till 0.0.0.0 är just det som den här uppdelningen finns för att
förhindra, och den hade sett ut att fungera.

### Måste finnas på provisionerings-/mgmt-VLAN:et

| Tjänst | Port | Vad |
|---|---|---|
| Kea DHCPv4 | 67/udp | broadcast; kräver L2-närvaro eller en DHCP-relay (se §5) |
| tftpd-hpa | 69/udp | UEFI-PXE-grenen (`ipxe.efi`). Behåll den: en engångs-boot-override ber om `Pxe`, och firmware väljer själv mellan HTTP Boot och PXE |
| nginx | 80/tcp | hela bootkedjan, se nedan |

Port 80, endpoint för endpoint (`nginx.conf:41-118`):

| Väg | Anropas av | Steg |
|---|---|---|
| `/ipxe/` | UEFI HTTP Boot-firmware, iPXE | `ipxe.efi` (option 67), `boot.ipxe` |
| `/boot.ipxe.php?mac=` | iPXE | genererar bootskriptet, väntar på godkännande |
| `/mboot.efi` | UEFI HTTP Boot-firmware | oanvänd i standardkonfigurationen; kvar för den kortare kedjan utan väntläge |
| `/boot.cfg`, `/boot.cfg.php` | mboot | per-host boot.cfg med `prefix=`, `ks=`, `netdevice=` |
| `/esxi/<version>/…` | mboot | kernel + ~110 moduler |
| `/ks.cfg?mac=` | weasel (installern) | kickstart |
| `/progress.php?mac=&step=` | `%firstboot` | förloppsbeacon |
| `/deployment_complete.php`, `/admin/deployment_complete.php` | `%firstboot` | slutcallback |

### Måste finnas på admin-VLAN:et

| Väg | Port | Vem |
|---|---|---|
| `/admin/` | 443/tcp | driftpersonalens webbläsare |
| `/api/v1/…` | 443/tcp | automation med bearer-token |

### Utgående från deploy-servern

| Mål | Port | Vem |
|---|---|---|
| iLO/OOB-nätet | 443/tcp | `scripts/ilo_scanner.py`, `scripts/secure_boot_manager.py` (Redfish) |
| SMTP | 25/tcp | valfri notifiering vid autoregistrering (`boot.ipxe.php:139`) |

> **Notera:** BMC-certifikaten är självsignerade, så det finns ingen kedja att
> validera. `scripts/redfish_client.py` spelar in SHA-256 vid första kontakten
> och pinnar det därefter (`hosts.ilo_cert_sha256`); ett ändrat avtryck avvisas
> i stället för att skrivas om. Trafiken bär iLO-administratörslösenordet, så
> den vägen ska ändå inte routas över något mindre betrott än näten här.

### Den detalj som är lätt att missa

Slutcallbacken kommer **inte** från DHCP-leasen. Hosten har vid den tidpunkten
installerat klart, bootat om och rest sitt management-interface på den adress och
det VLAN som operatören angav vid godkännandet. Anropet går till
`{{SERVER_URL}}/admin/deployment_complete.php` — alltså till port 80 på den
adress som står i `webserver.url`.

I den uppställning som beskrivs här är DHCP-VLAN:et och ESXi-mgmt-VLAN:et samma,
och det fungerar. Delar du senare upp dem måste port 80 vara nåbar även från
mgmt-subnätet, annars fastnar varje host på "deploying" trots att installationen
lyckades. Samma sak gäller `progress.php`-beaconen i `%firstboot`.

---

## 2. Konfiguration

### 2.1 Adresserna sätts i en fil

```bash
cp config/install.example.conf install.conf
chmod 600 install.conf
sudo ./install.sh --config install.conf
```

`ADMIN_IP` är adressen dashboarden binds till, `SERVER_IP` deploy-adressen som
Kea annonserar som `next-server` och som varje URL i bootkedjan bygger på, och
`BMC_INTERFACE` interfacet mot iLO-nätet. Utelämnas `ADMIN_IP` faller den
tillbaka till `SERVER_IP` — det fungerar i ett labb, och `install.sh` säger då
uttryckligen att admin-gränssnittet ligger på deploy-nätet.

Filen läses rad för rad in i en fast lista av namn, aldrig med `source`. Den
namnger adresser och interface och ska inte också vara en väg att köra
kommandon som root.

Lägger du in `ILO_PASSWORD` eller `ESXI_PASSWORD` måste filen vara `0600`;
`install.sh` varnar annars. Lämnas de tomma frågar installern i stället, och då
hamnar de aldrig på disk.

### 2.2 Vad som binds var

| Tjänst | Bunden till | Var det sätts |
|---|---|---|
| nginx :80 | `SERVER_IP` | `nginx.conf`, platshållare `DEPLOY_IP` |
| nginx :443 | `ADMIN_IP` | `nginx.conf`, platshållare `ADMIN_IP` |
| nginx :80 (redirect till https) | `ADMIN_IP` | egen server-block |
| Kea DHCPv4 | `INTERFACE` | `interfaces-config` |
| tftpd-hpa | `SERVER_IP:69` | `/etc/default/tftpd-hpa` |
| Redfish (utgående) | route via `BMC_INTERFACE` | inget binds |

Certifikatets `subjectAltName` namnger `ADMIN_IP`, eftersom det är där 443
svarar.

### 2.3 Kea startar innan VLAN-subinterfacet finns

`interfaces-config` namnger interfacet explicit, vilket är rätt. Men ett
subinterface (`ens192.20`) reses av nätverkskonfigurationen, och Kea vägrar
starta om det inte finns när daemonen startar. Utan ordning mot
`network-online.target` blir det ett race vid varje omstart.

---

## 3. Två saker som biter när lyssnarna har adresser

* **`default_server` är per lyssnaradress, inte globalt.** Båda blocken kan
  alltså bära flaggan. Bootkedjan behöver den: firmware som hämtar en bootfil
  skickar ingen användbar Host-header.
* **nginx startar inte om adressen inte finns.** Med explicit adress i `listen`
  failar starten när VLAN-interfacet ännu inte är uppe vid boot. Antingen
  `sysctl net.ipv4.ip_nonlocal_bind=1`, eller en drop-in:

  ```ini
  # /etc/systemd/system/nginx.service.d/10-wait-for-network.conf
  [Unit]
  After=network-online.target
  Wants=network-online.target
  ```

Ett kvarvarande lager värt att lägga till: `allow`/`deny` på adminblocket.
Bindningen är det som gör att paketen aldrig kommer in; `allow` är det som
gäller om någon tar bort bindningen, eller om trafik routas dit från ett annat
nät.

## 4. `webserver.url` är deploy-adressen

Värt att veta innan någon "rättar" den: `webserver.ip` och `webserver.url` i
`global_config.json` ska vara **deploy-adressen**, inte adminadressen. De blir
`prefix=`, `ks=` och `{{SERVER_URL}}` i varje kickstart, och en host som ska
hämta sin kickstart sitter på deploy-nätet. Instinkten i en uppdelad
uppställning är att fylla i adminadressen där, och då slutar varje host att
kunna installeras.

---

## 5. Kea på ett VLAN-subinterface

### L2-närvaro (det normala här)

Deploy-VM:en har ett ben i provisionerings-VLAN:et:

```bash
sudo /srv/autodeploy/deploy/kea-config.sh \
     --interface ens192.20 --server-ip 10.20.0.2 \
     --subnet 10.20.0.0/24 --pool "10.20.0.100 - 10.20.0.200" \
     --gateway 10.20.0.1 --dns 10.20.0.53 \
     > /etc/kea/kea-dhcp4.conf
```

Subinterfacenamnet skickas rakt igenom till `interfaces-config` — Kea hanterar
`ens192.20` precis som vilket interfacenamn som helst.

Lägg till en drop-in så Kea inte startar före interfacet:

```ini
# /etc/systemd/system/kea-dhcp4-server.service.d/20-wait-for-network.conf
[Unit]
After=network-online.target
Wants=network-online.target

[Service]
Restart=on-failure
RestartSec=5
```

### Alternativet: DHCP-relay

Har deploy-VM:en inget ben i hostarnas VLAN, utan L3-switchen gör
`ip helper-address`, behöver konfigurationen två saker som `kea-config.sh` inte
genererar idag:

```json
"interfaces-config": { "interfaces": [ "ens192.10" ] },   // det ben relayen når
"subnet4": [{
    "subnet": "10.20.0.0/24",
    "relay": { "ip-addresses": [ "10.20.0.1" ] },          // switchens SVI
    ...
}]
```

Fungerar, men bootkedjans HTTP-trafik måste då också routas mellan näten, och
`next-server`/`boot-file-name` måste peka på en adress hostarna kan nå. L2-närvaro
är enklare och är vad resten av dokumentationen förutsätter.

**Föreslagen ändring:** `deploy/kea-config.sh` får en `--relay ADDR`-flagga som
lägger till `relay`-blocket. Notera att `lib/kea.php:277-284` bygger om
`subnet4[0]` från grunden när DHCP ändras från admin-UI:t och **skulle tappa
`relay`-nyckeln** — den måste bevaras på samma sätt som `id` och `reservations`
redan bevaras.

---

## 5b. Kea svarar på **all** DHCP i broadcastdomänen — läs det här först

`deploy/kea-config.sh:166-186`

```json
"subnet4": [
    {
        "id": 1,
        "subnet": "$SUBNET",
        "pools": [ { "pool": "$POOL" } ],
        "next-server": "$SERVER_IP",
        "option-data": [ ... ],
        "reservations": [ ]
    }
]
```

Det finns ingen `client-class` på subnätet. De tre klasserna ovanför —
`iPXE`, `UEFI-HTTP`, `UEFI-PXE` — sätter bara `boot-file-name` och
`next-server` **för klienter som redan fått en lease**. De avgör inte *vem* som
får en.

Konsekvensen: Kea besvarar varje DHCPDISCOVER som når interfacet. Laptops,
skrivare, virtuella maskiner, allt. På ett dedikerat provisioneringsnät är det
avsikten. På ett nät som redan har en DHCP-server är det en rogue DHCP-server som
tävlar med den befintliga — den som svarar först vinner, och klienten får
`routers` och `domain-name-servers` ur *den här* konfigurationen.

Det är särskilt lätt att hamna där eftersom `install.sh` föreslår
deploy-serverns eget interface och egen adress som default. Kör man igenom
frågorna med enter hamnar DHCP:n på samma nät som maskinen administreras från.

### Åtgärd i `deploy/kea-config.sh`

Lägg till en klass som är unionen av de tre bootklasserna, och bind subnätet till
den. Klasser evalueras i definitionsordning, så den måste stå **sist** i
`client-classes`:

```json
{
    // Unionen av bootgrenarna. Subnätet nedan är bundet till den här
    // klassen, så en maskin som inte nätbootar får ingen lease alls.
    // Utan det svarar Kea på varje DHCPDISCOVER i broadcastdomänen, vilket
    // på ett delat nät gör den till en rogue DHCP-server.
    "name": "PXE-CLIENTS",
    "test": "member('iPXE') or member('UEFI-HTTP') or member('UEFI-PXE')"
}
```

```json
"subnet4": [
    {
        "id": 1,
        "subnet": "$SUBNET",
        "client-class": "PXE-CLIENTS",
        ...
    }
]
```

ESXi-hostarna får statiska adresser vid godkännandet (`management_ip`), så de
behöver leasen bara under installationen. Ingenting i kedjan tappar något på
restriktionen.

`lib/kea.php:277-284` bygger om `subnet4[0]` från grunden när DHCP ändras från
admin-UI:t och bevarar idag bara `id` och `reservations`. `client-class` måste
läggas till i den listan, annars försvinner skyddet vid första
nätverksändringen — samma sak som gäller `relay` i §5.

### Om du ändå måste dela nät med en befintlig DHCP-server

Restriktionen ovan minskar risken men tar inte bort den: två DHCP-servrar i samma
broadcastdomän är fortfarande en tävling, och en annan maskin som råkar nätboota
på det nätet kommer att få hostdeployers svar. Rätt lösning är ett eget VLAN för
provisionering — det är hela poängen med uppdelningen som resten av det här
dokumentet beskriver.

Innan du startar Kea på ett delat nät, kontrollera åtminstone att poolen inte
överlappar den befintliga serverns:

```bash
# Vilken server gav den här maskinen sin adress?
journalctl -u NetworkManager --no-pager | grep -i dhcp | tail
grep -r . /var/lib/dhcp/ 2>/dev/null | tail

# Svarar någon annan redan på nätet?
sudo nmap --script broadcast-dhcp-discover
```

---

## 6. vSphere-sidan

### Ett vNIC med flera VLAN (VGT) — det du beskriver

* Portgroup med **VLAN ID 4095** (Virtual Guest Tagging). ESXi skickar ramarna
  taggade till gästen, som själv reser 802.1Q-subinterface.
* Den fysiska uppleveransen måste vara en trunk som släpper igenom båda VLAN:en.
* I gästen (Debian, `/etc/network/interfaces` eller netplan): `ens192.10` för
  admin, `ens192.20` för provisionering. `ens192` själv får ingen adress.
* Fungerar, men allt VLAN-fel blir ett gästfel: felstavat VLAN-ID, saknat
  `vlan`-paket, ett subinterface som inte reses vid boot.

### Två vNIC med varsitt VLAN (VST) — rekommendationen

* Två portgroups, var och en med ett fast VLAN-ID. Gästen ser två vanliga
  interface utan taggning.
* Färre rörliga delar, och — det viktiga här — **säkerhetspolicy per portgroup**.
  Provisioneringsportgroupen kan sättas hårdare än adminportgroupen.
* `listen <ip>:80` / `listen <ip>:443` blir triviala att resonera om.

### Portgroup-policy, båda varianterna

| Inställning | Värde | Varför |
|---|---|---|
| Promiscuous mode | Reject | deploy-VM:en behöver inte se andras trafik |
| MAC address changes | Reject | den spoofar ingen MAC |
| Forged transmits | Reject | den skickar bara från sin egen MAC |

Ingen del av hostdeployer behöver någon av dem påslagen. `netdevice=`-parametern
som `renderBootCfg()` skriver in binder installern till hostens egen NIC — det är
en klient-sida-inställning och kräver inget på portgroupen.

---

## 7. Brandvägg på deploy-VM:en

Bindningarna i §3 är den primära kontrollen; nftables är den som fortsätter gälla
när någon lägger till en tjänst och glömmer binda den.

```nft
# /etc/nftables.conf
table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;

        ct state established,related accept
        iif lo accept

        # Provisioneringsnätet: DHCP, TFTP och bootkedjan över HTTP.
        iifname "ens192.20" udp dport { 67, 69 } accept
        iifname "ens192.20" tcp dport 80 accept

        # Adminnätet: bara dashboard och API, och bara från driftnätet.
        iifname "ens192.10" ip saddr 10.10.0.0/24 tcp dport { 22, 80, 443 } accept

        # ICMP för felsökning.
        ip protocol icmp accept
    }
}
```

Anpassa interfacenamn och nät. Poängen är att port 443 aldrig accepteras på
provisioneringsbenet och port 67/69 aldrig på adminbenet.

---

## 8. Sammanfattning: vilken adress hör hemma var

| Inställning | Fil | Värde |
|---|---|---|
| `webserver.ip`, `webserver.url` | `config/global_config.json` | **provisioneringsadressen** |
| `next-server`, `boot-file-name` | `/etc/kea/kea-dhcp4.conf` | **provisioneringsadressen** |
| `interfaces-config.interfaces` | `/etc/kea/kea-dhcp4.conf` | **provisionerings-subinterfacet** |
| `TFTP_ADDRESS` | `/etc/default/tftpd-hpa` | **provisioneringsadressen**:69 |
| `listen …:80` (bootkedjan) | nginx-siten | **provisioneringsadressen** |
| `listen …:443` | nginx-siten | **adminadressen** |
| certifikatets SAN | `/etc/ssl/autodeploy/server.crt` | **adminadressen** + FQDN |
| `AUTODEPLOY_API_URL` | php-fpm-poolen / helper-miljön | `https://127.0.0.1/api/v1` (loopback — hjälpskripten kör lokalt) |

---

## 9. Föreslagna kodändringar

| Prio | Var | Ändring |
|---|---|---|
| ~~P1~~ | `nginx.conf` | ~~dela lyssnarna per adress~~ — gjort; `DEPLOY_IP`/`ADMIN_IP` mallas in av install.sh |
| ~~P1~~ | `install.sh` | ~~`--admin-ip`, mallning av nginx-siten, SAN på adminadressen~~ — gjort, plus `--config` |
| ~~P1~~ | `install.sh` | ~~binda `TFTP_ADDRESS`~~ — gjort |
| P1 | `nginx.conf` | `allow`/`deny` på adminblocket som andra lager bakom bindningen |
| P2 | `install.sh` | systemd drop-ins: nginx och Kea efter `network-online.target` |
| P2 | `deploy/kea-config.sh` | `--relay ADDR` för uppställningar utan L2-närvaro |
| P2 | `lib/kea.php:277` | bevara `relay` (och `client-class`) när `subnet4[0]` byggs om, som `id` och `reservations` redan bevaras |
| P3 | `config/global_config.example.json` | kommentera att `webserver.ip` är provisioneringsadressen, inte adminadressen |
