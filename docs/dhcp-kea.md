# Migrera från ISC dhcpd till Kea

ISC dhcpd är EOL sedan december 2022. Kea är ISC:s egen ersättare och ger den
här lösningen ett riktigt API i stället för "skriv om filen och starta om".

## 1. Installera

```bash
apt install kea-dhcp4-server kea-ctrl-agent      # Debian/Ubuntu
# dnf install kea                                # RHEL/Rocky
```

## 2. Konfigurera

Utgå från `dhcp/kea-dhcp4.conf` i det här repot. Byt ut:

- `interfaces` — vilket interface som lyssnar
- `subnet`, `pools`, `routers`, `domain-name-servers`
- `next-server` och URL:erna i `client-classes` → deployment-serverns adress

```bash
cp dhcp/kea-dhcp4.conf /etc/kea/kea-dhcp4.conf
kea-dhcp4 -t /etc/kea/kea-dhcp4.conf      # validera
systemctl enable --now kea-dhcp4-server
```

Eller låt admin-UI:t generera filen:

```bash
DHCP_BACKEND=kea /usr/local/bin/update_dhcp_config.sh \
    192.0.2.100 192.0.2.200 255.255.255.0 192.0.2.1 192.0.2.53 192.0.2.10
```

Sätt `DHCP_BACKEND=kea` i PHP-FPM-miljön (`env[DHCP_BACKEND] = kea` i poolens
konfiguration) så att UI:t använder Kea-grenen.

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
firmware svaret.

`UEFI-PXE` är den enda grenen som behöver en tftpd. Har alla servrar HTTP Boot i
firmware kan du ta bort klassen och avinstallera tftpd.

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
`management_ip` till `hosts.json`. Med Kea kan den samtidigt skicka en
`reservation-add`, så servern får rätt adress redan under installationen i
stället för en slumpmässig pool-adress. Det är inte implementerat än.
