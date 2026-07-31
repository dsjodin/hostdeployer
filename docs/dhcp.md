# DHCP med Kea

hostdeployer stödjer **bara Kea**. ISC dhcpd är end-of-life sedan december
2022, finns inte i Debian 13, och — det avgörande — har inget kontroll-API.
Utan API måste varje ändring skrivas till en fil och tjänsten startas om, vilket
kräver att webbservern kör något som root och tappar varje pågående
DHCP-förhandling. Att stödja båda hade betytt två konfigurationsgeneratorer och
att sudo-regeln fick leva kvar för den döda av dem.

## 1. Installation

`install.sh` gör allt det här. Manuellt:

```bash
apt install kea-dhcp4-server kea-ctrl-agent
```

## 2. Konfiguration

`deploy/kea-config.sh` genererar filen. Det finns med flit ingen statisk
exempelfil i repot: en sådan är en andra kopia av klassdefinitionerna, och den
kopian drev isär från generatorn två gånger under utvecklingen — båda gångerna
genom att peka UEFI HTTP Boot på fel laddare.

```bash
deploy/kea-config.sh \
    --interface ens192 --server-ip 192.0.2.10 \
    --subnet 192.0.2.0/24 --pool "192.0.2.100 - 192.0.2.200" \
    --gateway 192.0.2.1 --dns 192.0.2.53 --domain lab.local \
    > /etc/kea/kea-dhcp4.conf

kea-dhcp4 -t /etc/kea/kea-dhcp4.conf      # validera
systemctl enable --now kea-dhcp4-server
```

Det behövs bara vid installation, eller för att bygga om filen från grunden när
Kea inte startar och alltså inte har någon socket att prata med. Löpande
ändringar går via API:t.

## 2b. Ändringar i drift går via API:t, inte via filen

Admin-UI:t skriver **inte** om `/etc/kea/kea-dhcp4.conf` när du ändrar
nätverksinställningar. Den pratar med Keas kontrollsocket (`lib/kea.php`):

```
config-test   validerar — en config Kea inte accepterar ersätter aldrig den som kör
config-set    tillämpar på den körande servern, utan omstart
config-write  persisterar, så en omstart kommer tillbaka till det som kördes
```

Det tar bort tre saker den filbaserade vägen hade:

- **sudo-regeln.** Webbservern körde tidigare ett skript som root för att kunna
  skriva under `/etc` och starta om en tjänst. Nu behövs skrivrättighet på en
  unix-socket, inget mer.
- **Omstarten**, som tappade varje pågående DHCP-förhandling i just det
  ögonblicket — inklusive servrar mitt i en installation.
- **Kapplöpningen** mellan att läsa filen, ändra den och skriva tillbaka.

Socketen är den nya förtroendegränsen: det som kan skriva till den kan
konfigurera om DHCP för hela provisioneringsnätet. `install.sh` ger den till
webbserverns grupp och inget bredare.

Klientklasserna rörs aldrig av UI:t. De är en egenskap hos bootkedjan, inte hos
operatörens adressplan — och det var precis genom att regenerera dem som den
gamla vägen upprepade gånger rullade tillbaka bootmetoden.

## 3. Klassificering — ordningen spelar roll

```
iPXE       (option 77 == "iPXE")        →  http://<server>/ipxe/boot.ipxe
UEFI-HTTP  (option 60 == "HTTPClient")  →  http://<server>/ipxe/ipxe.efi
UEFI-PXE   (option 93 == 7 / 9 / 11)    →  ipxe.efi via TFTP
```

`iPXE` **måste** testas först. En maskin som redan chainloadat iPXE skickar både
option 77 och sin arch-kod; matchar UEFI-grenen först får den `ipxe.efi` igen
och loopar för evigt. Därför har de senare klasserna `not member('iPXE')`.

`UEFI-HTTP` måste echo:a tillbaka `HTTPClient` i option 60, annars ignorerar
firmware svaret. Den delar ut **iPXE**, inte ESXi-laddaren. Att gå direkt på
`/mboot.efi` fungerar, men hoppar över det enda steget i kedjan som kan vänta:
en host som ännu inte är godkänd har ingen möjlighet att polla, eftersom
`boot.cfg` serveras till en UEFI-laddare som inte kan göra om försöket. Via
iPXE parkerar samma host i retry-loopen i `boot.ipxe.php` och börjar installera
i samma stund som den godkänns.

`UEFI-PXE` är den enda grenen som behöver en tftpd. Behåll den även om alla
servrar klarar HTTP Boot: en engångs-boot-override via Redfish ber om `Pxe`, och
vilken nätverkspost firmware då väljer är dess beslut. Båda klasserna delar ut
samma `ipxe.efi`, så valet spelar ingen roll.

`www/mboot.efi.php` finns kvar och fungerar. Peka `UEFI-HTTP` på den igen om du
vill ha ESXi-laddaren direkt — men då försvinner väntläget för hostar som inte
är godkända.

> `ipxe.efi` är osignerad, så en host med Secure Boot påslaget vägrar ladda den.
> Servrar levereras med Secure Boot på, och det stängs av över Redfish innan
> första boot. Se `docs/bootchain.md`.

Legacy BIOS finns medvetet inte med: ESXi 8 kräver UEFI.

## 4. Aktivera API:t

```bash
systemctl enable --now kea-ctrl-agent
```

`control-socket` i konfigurationen är unix-socketen som `kea-ctrl-agent` och
`kea-shell` pratar med.

Testa:

```bash
echo '{"command":"config-get","service":["dhcp4"]}' | kea-shell --host 127.0.0.1 --port 8000
```

## 5. Vanliga anrop

Uppdatera poolen utan omstart:

```bash
echo '{"command":"subnet4-update","service":["dhcp4"],"arguments":{"subnet4":[{
  "id":1,"subnet":"192.0.2.0/24",
  "pools":[{"pool":"192.0.2.120 - 192.0.2.180"}],
  "next-server":"192.0.2.10"
}]}}' | kea-shell --host 127.0.0.1 --port 8000

echo '{"command":"config-write","service":["dhcp4"]}' | kea-shell --host 127.0.0.1 --port 8000
```

Reservera en adress för en host som just godkänts:

```bash
echo '{"command":"reservation-add","service":["dhcp4"],"arguments":{"reservation":{
  "subnet-id":1,"hw-address":"88:e9:a4:d6:59:2a",
  "ip-address":"198.51.100.11","hostname":"esxi01"
}}}' | kea-shell --host 127.0.0.1 --port 8000
```

Lista leases:

```bash
echo '{"command":"lease4-get-all","service":["dhcp4"]}' | kea-shell --host 127.0.0.1 --port 8000
```

> `subnet4-update` och `reservation-add` ändrar bara den körande instansen.
> Kör `config-write` efteråt om ändringen ska överleva en omstart.

## 6. Nästa steg för integrationen

`processApproveHostAction()` i `www/host_functions.php` skriver idag bara
`management_ip` till inventariet. Med kontrollsocketen på plats (`lib/kea.php`)
kan den samtidigt skicka en `reservation-add`, så servern får sin riktiga adress
redan under installationen i stället för en slumpmässig pool-adress. Byggstenen
finns — `keaCommand('reservation-add', ...)` — men anropet är inte inkopplat.

`keaUpdateNetwork()` bevarar redan befintliga `reservations` när subnätet
uppdateras, så det steget kommer inte att slå sönder något som lagts till för
hand under tiden.
