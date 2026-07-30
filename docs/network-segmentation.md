# Nätverkssegmentering — admin-VLAN och provisioneringsVLAN

Den typiska produktionsuppställningen: admin-UI:t på ett förvaltnings-VLAN som
bara driftpersonal når, och DHCP:n på ESXi-hostarnas management-VLAN, eftersom
DHCP är broadcast och måste ligga i samma L2-domän som de maskiner som ska
installeras. En vNIC på en vSphere-portgroup som bär flera VLAN.

Det här dokumentet beskriver vad som måste vara nåbart var, vad i koden som inte
stödjer uppdelningen idag, och hur det ska ändras.

---

## 1. Vilka endpoints hostarna behöver

Bootkedjan är redan separerad i `nginx.conf`: port 80 innehåller exakt det
firmware behöver, port 443 innehåller admin-UI:t och REST-API:t. Uppdelningen är
gjord — det som saknas är att de två lyssnar på *olika adresser*.

### Måste finnas på provisionerings-/mgmt-VLAN:et

| Tjänst | Port | Vad |
|---|---|---|
| Kea DHCPv4 | 67/udp | broadcast; kräver L2-närvaro eller en DHCP-relay (se §5) |
| tftpd-hpa | 69/udp | **bara** UEFI-PXE-grenen (`ipxe.efi`). Hostar som klarar UEFI HTTP Boot behöver den inte |
| nginx | 80/tcp | hela bootkedjan, se nedan |

Port 80, endpoint för endpoint (`nginx.conf:41-118`):

| Väg | Anropas av | Steg |
|---|---|---|
| `/ipxe/` | iPXE-firmware | `ipxe.efi`, `boot.ipxe` |
| `/boot.ipxe.php?mac=` | iPXE | genererar bootskriptet, väntar på godkännande |
| `/mboot.efi` | UEFI HTTP Boot-firmware | löser hostens ESXi-version, streamar laddaren |
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

> **Notera:** Redfish-anropen går med `verify=False`
> (`scripts/ilo_scanner.py`, `secure_boot_manager.py`). Trafiken bär iLO-lösenordet
> och autentiseras inte mot något certifikat — den vägen ska inte routas över
> något som inte är lika betrott som provisioneringsnätet självt.

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

## 2. Vad koden inte stödjer idag

### 2.1 nginx lyssnar på alla adresser (`nginx.conf:25-26, 142-143`)

```nginx
listen 80 default_server;
listen [::]:80 default_server;
...
listen 443 ssl;
listen [::]:443 ssl;
```

Utan adress betyder det **alla** interface. Konsekvensen är att admin-UI:t och
REST-API:t syns på provisioneringsnätet — där oautentiserade ESXi-installationer,
främmande hårdvara och allt annat som råkar bli inkopplat sitter. Loginsidan och
`/api/v1/` ska inte finnas där.

### 2.2 install.sh känner bara till ett interface och en adress

`--interface` och `--server-ip` används till tre olika saker samtidigt:

* Keas `interfaces-config` och `next-server` (`deploy/kea-config.sh`)
* `webserver.ip` / `webserver.url` i `global_config.json` — som blir `prefix=`,
  `ks=` och `{{SERVER_URL}}` i varje kickstart
* certifikatets `subjectAltName` och sammanfattningens dashboard-URL

I en delad uppställning ska de två första vara **provisioneringsadressen** och den
sista **adminadressen**. Det finns inget sätt att uttrycka det.

Validering på `install.sh:266-275` kräver dessutom att `--server-ip` ligger i
DHCP-subnätet — rätt för provisioneringsbenet, men det gör att adminadressen
måste bli en egen variabel.

### 2.3 tftpd-hpa binder alla adresser

`install.sh:708-713` skriver bara `TFTP_DIRECTORY`. `TFTP_ADDRESS` lämnas som
Debians `:69`, alltså alla interface.

### 2.4 Kea startar innan VLAN-subinterfacet finns

`interfaces-config` namnger interfacet explicit, vilket är rätt. Men ett
subinterface (`ens192.20`) reses av nätverkskonfigurationen, och Kea vägrar
starta om det inte finns när daemonen startar. Utan ordning mot
`network-online.target` blir det ett race vid varje omstart.

---

## 3. Föreslagen ändring i nginx.conf

Två lyssnare på två adresser, plus `allow`/`deny` som andra lager. Adresserna
mallas in av install.sh på samma sätt som FPM-socketen redan mallas in.

```nginx
# ---------------------------------------------------------------------------
# Provisioneringsnätet: bootkedjan. Ingen autentisering — klienterna är
# firmware utan credentials — så den här lyssnaren får inte finnas någon
# annanstans än på det nät som är avsett för att installera maskiner.
# ---------------------------------------------------------------------------
server {
    listen @PROVISIONING_IP@:80 default_server;
    server_name _;

    # ... befintliga location-block för /ipxe/, /esxi/, /boot.ipxe.php,
    #     /ks.cfg, /mboot.efi, /boot.cfg, /boot.cfg.php, /progress.php,
    #     /deployment_complete.php, /admin/deployment_complete.php ...

    # Admin-UI:t finns inte på det här nätet. Ingen redirect till HTTPS:
    # en redirect avslöjar var det ligger.
    location / { return 404; }
}

# ---------------------------------------------------------------------------
# Adminnätet: dashboard och REST-API. Bara TLS.
# ---------------------------------------------------------------------------
server {
    listen @ADMIN_IP@:443 ssl default_server;
    http2 on;
    server_name _;

    # Andra lagret. Bindningen ovan är det som gör att paketen aldrig kommer
    # in; det här är det som gäller om någon tar bort den, eller om trafiken
    # routas hit från ett annat nät.
    allow @ADMIN_CIDR@;
    allow 127.0.0.1;
    deny  all;

    # ... befintlig konfiguration ...
}

# Bara för att skicka operatörer som skriver http:// vidare till TLS.
server {
    listen @ADMIN_IP@:80;
    server_name _;
    location / { return 301 https://$host$request_uri; }
}
```

Punkter värda att veta:

* **`default_server` per adress.** `default_server` är per lyssnaradress, inte
  globalt, så båda blocken kan bära flaggan. Bootkedjan behöver den: firmware som
  hämtar `/mboot.efi` skickar ingen användbar Host-header.
* **nginx startar inte om adressen saknas.** Med en explicit adress i `listen`
  failar starten om VLAN-interfacet ännu inte är uppe. Antingen
  `sysctl net.ipv4.ip_nonlocal_bind=1`, eller en drop-in som ordnar nginx efter
  `network-online.target`:

  ```ini
  # /etc/systemd/system/nginx.service.d/10-wait-for-network.conf
  [Unit]
  After=network-online.target
  Wants=network-online.target
  ```
* **Vill du inte binda per adress** — till exempel för att adresserna ändras — så
  räcker `allow`/`deny` i 443-blocket ensamt som ett meningsfullt lyft mot idag.
  Kombinationen är bättre.
* **`Strict-Transport-Security`** ska bara sättas på adminlyssnaren, vilket den
  redan gör. Skulle den hamna på port 80-svaret hade den brutit bootkedjan för
  klienter som tar hänsyn till den.

---

## 4. Föreslagen ändring i install.sh

Nya flaggor, med bakåtkompatibel default (ett ben = som idag):

```
--interface NAME          provisioneringsinterface: Kea och port 80
--server-ip ADDR          adress på provisioneringsnätet
--admin-interface NAME    interface för admin-UI:t          (default: --interface)
--admin-ip ADDR           adress admin-UI:t binds till      (default: --server-ip)
--admin-allow CIDR        nät som får nå /admin och /api    (default: adminadressens /24)
```

och i nginx-steget (`install.sh:657-658`), där idag bara FPM-socketen mallas in:

```bash
sed -e "s#server unix:/var/run/php/php-fpm.sock;#server unix:${FPM_SOCKET};#" \
    -e "s#@PROVISIONING_IP@#${SERVER_IP}#g" \
    -e "s#@ADMIN_IP@#${ADMIN_IP}#g" \
    -e "s#@ADMIN_CIDR@#${ADMIN_ALLOW}#g" \
    "$ROOT/nginx.conf" > /etc/nginx/sites-available/autodeploy
```

Övrigt i samma steg:

* Certifikatets SAN ska innehålla **adminadressen** (`install.sh:640` sätter
  `IP:$SERVER_IP` — fel adress i en delad uppställning).
* Slutsammanfattningen ska visa `https://$ADMIN_IP/admin/` och
  `http://$SERVER_IP/` för bootkedjan.
* `TFTP_ADDRESS` ska bindas:

  ```bash
  sed -i "s#^TFTP_ADDRESS=.*#TFTP_ADDRESS=\"${SERVER_IP}:69\"#" /etc/default/tftpd-hpa
  ```
* `webserver.ip` och `webserver.url` i `global_config.json` ska fortsätta vara
  `$SERVER_IP`, alltså provisioneringsadressen. Det är redan korrekt — men det är
  värt en kommentar i filen, för instinkten i en delad uppställning är att fylla i
  adminadressen där, och då slutar varje host att kunna hämta sin kickstart.

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
| P1 | `nginx.conf` | dela lyssnarna per adress; `allow`/`deny` på adminblocket; platshållare som install.sh mallar in |
| P1 | `install.sh` | `--admin-interface`, `--admin-ip`, `--admin-allow`; mallning av nginx-siten; SAN på adminadressen |
| P1 | `install.sh` | binda `TFTP_ADDRESS` till provisioneringsadressen |
| P2 | `install.sh` | systemd drop-ins: nginx och Kea efter `network-online.target` |
| P2 | `deploy/kea-config.sh` | `--relay ADDR` för uppställningar utan L2-närvaro |
| P2 | `lib/kea.php:277` | bevara `relay` (och `client-class`) när `subnet4[0]` byggs om, som `id` och `reservations` redan bevaras |
| P3 | `config/global_config.example.json` | kommentera att `webserver.ip` är provisioneringsadressen, inte adminadressen |
