# Bootkedjan

Från strömpåslag till färdig ESXi-host.

```
 ┌──────────┐
 │  Server  │  UEFI, nätverksboot
 └────┬─────┘
      │ 1. DHCP DISCOVER  (option 60 = HTTPClient / option 93 = arch)
      ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  DHCP (Kea eller ISC dhcpd)                                  │
 │    next-server = <deployment-server>                         │
 │    filename    = http://<server>/ipxe/ipxe.efi               │
 │                  (eller /ipxe/boot.ipxe om klienten är iPXE) │
 └────┬─────────────────────────────────────────────────────────┘
      │ 2. HTTP GET /ipxe/ipxe.efi
      ▼
 ┌──────────┐
 │ ipxe.efi │  iPXE startar, gör egen DHCP, sätter option 77 = "iPXE"
 └────┬─────┘
      │ 3. DHCP igen → matchar nu klassen "iPXE" → /ipxe/boot.ipxe
      ▼
 ┌───────────────────────────────────────────────────┐
 │  ipxe/boot.ipxe                                   │
 │    skriver ut MAC / serienummer / modell          │
 │    chain http://${next-server}/boot.ipxe.php      │
 │          ?mac=${net0/mac}&serial=${smbios/serial} │
 │    5 försök med 15 s paus, annars nästa bootenhet │
 └────┬──────────────────────────────────────────────┘
      │ 4. HTTP GET /boot.ipxe.php?mac=..
      ▼
 ┌────────────────────────────────────────────────────────────────┐
 │  www/boot.ipxe.php                                             │
 │                                                                │
 │   slår upp MAC i hosts.json (även additional_macs)             │
 │                                                                │
 │   okänd MAC + autoreg på  → registrera som pending → vänta     │
 │   okänd MAC + autoreg av  → vänta, max 5 försök                │
 │   pending                 → vänta + retry (max_wait_time)      │
 │   deployed                → exit 1 → boota lokal disk          │
 │   approved                → sätt "deploying", fortsätt         │
 │   deploying               → fortsätt (tål omstart mitt i)      │
 │                                                                │
 │   läser esxi/<version>/boot.cfg → kernel + ~110 moduler        │
 └────┬───────────────────────────────────────────────────────────┘
      │ 5. genererat iPXE-skript:
      │      kernel <url>/b.b00 runweasel ks=<url>/ks.cfg?mac=..
      │      module <url>/jumpstrt.gz
      │      module ... (~110 rader)
      │      boot
      ▼
 ┌─────────────────┐
 │ ESXi-installer  │  mboot laddar kärna + moduler över HTTP
 └────┬────────────┘
      │ 6. HTTP GET /ks.cfg?mac=..
      ▼
 ┌────────────────────────────────────────────────────────┐
 │  www/generate_kickstart.php                            │
 │    väljer mall efter deployment_type (standard / vcf)  │
 │    fyller i IP, netmask, gateway, VLAN, DNS, NTP, FQDN │
 │    hashar root-lösenordet (host-specifikt om satt)     │
 │    sätter "deploying"                                  │
 │    pending → waiting_template (installerar INTE)       │
 └────┬───────────────────────────────────────────────────┘
      │ 7. installation + reboot + %firstboot
      ▼
 ┌────────────────────────────────────────────────┐
 │  www/deployment_complete.php?mac=..            │
 │    sätter "deployed"                           │
 │    slår på secure boot igen om konfigurerat    │
 └────────────────────────────────────────────────┘
```

## Statusmaskin

```
        (okänd MAC, autoreg på)
                 │
                 ▼
            ┌─────────┐   operatör godkänner   ┌──────────┐
            │ pending │ ─────────────────────► │ approved │
            └─────────┘                        └────┬─────┘
                 ▲                                  │ server bootar
                 │                                  ▼
                 │                             ┌───────────┐
                 │                             │ deploying │◄── tål omstart
                 │                             └────┬──────┘
                 │                                  │ %firstboot-callback
                 │                                  ▼
                 │        "Reinstall"           ┌──────────┐
                 └──────────────────────────────│ deployed │──► lokal disk
                          (→ approved)          └──────────┘
```

## URL:er

| URL | Fil | Port | Autentisering |
|---|---|---|---|
| `/ipxe/ipxe.efi` | statisk | 80 | nej |
| `/ipxe/boot.ipxe` | statisk | 80 | nej |
| `/boot.ipxe.php?mac=` | `www/boot.ipxe.php` | 80 | nej |
| `/esxi/<ver>/*` | statisk | 80 | nej |
| `/ks.cfg?mac=` | `www/generate_kickstart.php` | 80 | nej |
| `/admin/deployment_complete.php?mac=` | `www/deployment_complete.php` | 80 | nej |
| `/admin/` | `www/admin_dashboard.php` | **443** | session + CSRF |

Bootändpunkterna kan inte kräva autentisering — klienten är en firmware utan
credentials. Skyddet är nätverkssegmentering: kör provisioneringen på ett eget
VLAN.

## Felsökning

| Symptom | Titta på |
|---|---|
| Servern får ingen adress | DHCP-loggen; matchar arch/vendor-class någon klass? |
| iPXE laddas om och om igen | `iPXE`-klassen testas inte först → loop |
| "Could not reach the deployment server" | `next-server` fel, eller nginx nere |
| Installer startar men hittar inga moduler | `esxi/<ver>/boot.cfg` saknas eller har fel `modules=` |
| Hosten fastnar i väntloop | status ≠ approved i `hosts.json`; `logs/ipxe_boot.log` |
| Kickstart avbryts direkt | hosten inte godkänd, eller `waiting_template_path` pekar fel |
| Hosten blir aldrig `deployed` | `%firstboot`-callbacken; `logs/deployment.log` |

Loggar: `logs/ipxe_boot.log`, `logs/kickstart_generator.log`,
`logs/deployment.log`, `logs/admin_dashboard.log`, `logs/auth.log`.
