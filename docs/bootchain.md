# Bootkedjan

Från strömpåslag till färdig ESXi-host.

```
 ┌──────────┐
 │  Server  │  UEFI, nätverksboot
 └────┬─────┘
      │ 1. DHCP DISCOVER  (option 60 = HTTPClient / option 93 = arch)
      ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  DHCP (Kea)                                                  │
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
 │   slår upp MAC i inventariet (även sekundära MAC-adresser)    │
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
      │      chain <url>/esxi/<ver>/efi/boot/mboot.efi \
      │            -c <url>/boot.cfg.php?mac=..
      │      (saknas mboot i mediet: kernel + ~110 module-rader i stället)
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

## Alla vägar går genom iPXE

DHCP-klassen väljer transport, men båda nätverksgrenarna delar ut samma
`ipxe.efi`. Det är avsiktligt: iPXE är det enda steget i kedjan som kan vänta.

```
  UEFI HTTP Boot                          UEFI PXE
  (option 60 = HTTPClient)                (option 93 = arch 7/9/11)
        │                                       │
        │ option 67 =                           │ next-server + ipxe.efi
        │   http://srv/ipxe/ipxe.efi            │ över TFTP
        └───────────────┬───────────────────────┘
                        ▼
                  ipxe.efi startar
                  gör egen DHCP, option 77 = "iPXE"
                        ▼
                  ipxe/boot.ipxe
                        ▼
                 www/boot.ipxe.php
                  godkännandegrind + väntloop
                        ▼
                  chain <mboot> -c <boot.cfg.php>
                        ▼
                 www/boot.cfg.php
                        ▼
                   ESXi-installer
```

**Varför inte `/mboot.efi` direkt.** Det fungerar — `www/mboot.efi.php` finns
kvar och gör rätt — men en UEFI-laddare kan inte polla. En host som inte är
godkänd får 403 från `boot.cfg.php`, mboot avbryter, och firmware går vidare
till nästa bootenhet. Ingenting väntar, och ingenting registrerar en okänd host.
Genom iPXE hamnar samma host i retry-loopen i `boot.ipxe.php` och startar
installationen i samma stund som operatören godkänner den.

Peka `UEFI-HTTP`-klassen på `/mboot.efi` igen om du vill ha den kortare kedjan
och kan leva utan väntläget.

**Secure Boot.** `ipxe.efi` är osignerad, så en host med Secure Boot påslaget
vägrar ladda den — och servrar levereras med Secure Boot på. Det stängs av över
Redfish innan första boot och slås på igen av `deployment_complete.php`. Det är
därför iLO måste vara nåbart innan hosten bootar första gången, och därför
upptäckten av ny hårdvara går via iLO i stället för via DHCP.

**Varför inte 110 `module`-rader längre.** iPXE-vägen räknade tidigare upp
varje modul ur `boot.cfg` i sitt eget skript. Det är en återimplementation av
vad `mboot` redan gör, och den går sönder varje gång en release ändrar sin
modullista. Nu chainar iPXE `mboot` och pekar den på samma genererade
`boot.cfg` som HTTP Boot-vägen får.

Saknas `mboot` i det uppackade mediet faller `boot.ipxe.php` tillbaka till
modulräkningen och loggar en varning — hårdvara som redan fungerar slutar inte
fungera för att mediet är ovanligt uppackat.

**Varför en omskrivning och inte två.** via_go hade den här omskrivningen
implementerad två gånger, en för TFTP och en för HTTP. De drev isär: en fix som
tog bort `cdromBoot` nådde bara den ena, så PXE-bootade hostar fick en annan
kommandorad än HTTP-bootade. `renderBootCfg()` har två anropare och ingen
kopia.

**Varför MAC:en inte alltid finns i URL:en.** DHCP option 67 namnger *en* URL
för hela klassen, så en HTTP Boot-firmware kan inte skicka sin MAC. Servern
identifierar då klienten på dess adress, samma fallback som
`generate_kickstart.php` redan använder. iPXE-vägen skickar alltid `?mac=`.

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
| `/mboot.efi` | `www/mboot.efi.php` | 80 | nej |
| `/boot.cfg` | `www/boot.cfg.php` | 80 | nej |
| `/boot.cfg.php?mac=` | `www/boot.cfg.php` | 80 | nej |
| `/ipxe/ipxe.efi` | statisk | 80 | nej |
| `/ipxe/boot.ipxe` | statisk | 80 | nej |
| `/boot.ipxe.php?mac=` | `www/boot.ipxe.php` | 80 | nej |
| `/esxi/<ver>/*` | statisk | 80 | nej |
| `/ks.cfg?mac=` | `www/generate_kickstart.php` | 80 | nej |
| `/progress.php?mac=&step=` | `www/progress.php` | 80 | nej |
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
| Hosten fastnar i väntloop | status ≠ approved i inventariet; `logs/ipxe_boot.log` |
| Kickstart avbryts direkt | hosten inte godkänd, eller `waiting_template_path` pekar fel |
| Hosten blir aldrig `deployed` | `%firstboot`-callbacken; `logs/deployment.log` |

## Progress

Bootkedjan rapporterar hur långt en host kommit. Procenten är checkpoints, inte
ett mått på utfört arbete: klienten säger var den nådde, och tystnad mellan två
checkpoints är diagnosen.

| % | Rapporteras av | Betyder |
|---|---|---|
| 10 | `boot.ipxe.php` | bootscript utfärdat; hämtar kärna + ~110 moduler |
| 50 | `generate_kickstart.php` | installern kör och har hämtat sin kickstart |
| 75 | `progress.php?step=firstboot` | `%firstboot` har börjat |
| 85 | `progress.php?step=network` | managementnätet konfigureras |
| 90 | `progress.php?step=services` | tjänster konfigureras |
| 100 | `deployment_complete.php` | klar |

Värdet backar aldrig. En host som gör om ett steg, eller bootar om i en redan
klar installation, ska inte se ut att tappa mark.

Stegnamnen i `progress.php` matchas mot en fast tabell (`storeProgressSteps()`).
En klient kan alltså inte skriva fri text i operatörens vy eller utropa sig
själv som färdig — det beslutet ligger hos `deployment_complete.php`, som också
slår på secure boot igen.

Dashboarden pollar `www/host_status.php` var tredje sekund. Polling och inte
SSE: php-fpm binder en worker per öppen SSE-anslutning, så tjugo operatörer som
tittar på tjugo installationer hade tömt poolen och tagit ner bootkedjan med
sig.

Loggar: `logs/ipxe_boot.log`, `logs/kickstart_generator.log`,
`logs/deployment.log`, `logs/admin_dashboard.log`, `logs/auth.log`.
