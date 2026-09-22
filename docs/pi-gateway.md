# Pi gateway: USB receipt printer as port 9100 in the tailnet

In the central setup (see [docker.md](docker.md) and [tailscale.md](tailscale.md)) the
Raspberry Pi in the restaurant carries no application code. It joins the tailnet as
`tag:printer` and exposes the USB printer as a raw ESC/POS port:

```
order-printer container ──tailnet, tcp/9100──▶ Pi: socat ──▶ /dev/bondrucker (usblp) ──USB──▶ printer
```

Everything on the Pi is set up by `dev-ops/pi-gateway/install.sh`, which is idempotent and
safe to re-run. It installs what is the same on every Pi; the customer identity (hostname,
tailnet login) is applied by `printer-gateway-provision`, either immediately or, on a flashed
image, at first boot from a file on the boot partition.

## Hardware

| Part          | Notes                                                                                   |
| ------------- | --------------------------------------------------------------------------------------- |
| Raspberry Pi  | any model with USB and network; a Pi Zero 2 W or Pi 3/4 is plenty                        |
| microSD card  | 8 GB or more, a brand that tolerates power loss (Samsung Pro Endurance, SanDisk MAX)     |
| Power supply  | the official one; brown-outs are the most common cause of "printer sometimes offline"   |
| Printer       | ESC/POS thermal printer with USB, recognised by the Linux `usblp` driver (`/dev/usb/lp0`) |
| Network       | Ethernet preferred, WLAN works; only outbound access is needed, no port forwarding        |

## Flash the image

1. Raspberry Pi Imager → **Raspberry Pi OS Lite (64-bit)**.
2. In the settings (gear icon): hostname anything (the script renames it), user `pi` with a
   password, WLAN credentials if there is no Ethernet, locale `Europe/Berlin`, **enable SSH**
   with password authentication. SSH over the LAN is only needed for the first run; the
   script closes it afterwards.
3. Boot the Pi with the printer connected and switched on.

## Run the script

From your laptop, with the Pi reachable in the LAN (its address is shown by your router, or
try `ssh pi@raspberrypi.local`):

```bash
scp -r dev-ops/pi-gateway pi@<pi-lan-ip>:
ssh pi@<pi-lan-ip>
sudo TS_AUTHKEY=tskey-auth-... CUSTOMER=<shop> bash pi-gateway/install.sh
```

`CUSTOMER` is the shop slug (`masala-mio`), the Pi becomes `printer-<shop>` in the tailnet.
`TS_AUTHKEY` is the reusable `tag:printer` key from the password manager (see
[tailscale.md](tailscale.md#auth-key-for-the-pi-image)); it is only needed on the first run.
Both may be left out: the Pi is then provisioned at first boot from a file, see below.

What the script does, in order:

| Step         | Result                                                                                                  |
| ------------ | ------------------------------------------------------------------------------------------------------- |
| Packages     | `socat`, `nftables`, `unattended-upgrades`, `curl`, `jq`                                                 |
| Tailscale    | installed and running, not yet logged in                                                                |
| Printer      | udev rule: every `usblp` printer becomes `/dev/bondrucker` (there is one printer per Pi)                |
| Bridge       | `printer-bridge.service`: `socat -u TCP-LISTEN:9100 … OPEN:/dev/bondrucker`, bound to the device unit   |
| Provisioning | `printer-gateway-provision` + service: hostname `printer-<shop>`, `tailscale up --ssh --accept-dns=false --advertise-tags=tag:printer`, auto-update on. Runs now with `CUSTOMER`/`TS_AUTHKEY`, otherwise at boot from `printer-gateway.env` |
| Firewall     | nftables: inbound only on `tailscale0` (plus DHCP, ICMP and Tailscale's UDP 41641); SSH via Tailscale   |
| Updates      | `unattended-upgrades` with automatic reboot at 04:30 when a kernel update needs it                      |
| Watchdog     | `dtparam=watchdog=on` and systemd `RuntimeWatchdogSec=15`: a hung Pi reboots itself                     |

The script ends with a summary and, on the first run, asks for a reboot to activate the
hardware watchdog. Reboot, then test.

### Provisioning from the boot partition

A Pi that was set up without `CUSTOMER` (or an SD card flashed from such an image) gets its
identity from `printer-gateway.env` on the boot partition, the FAT partition that Windows and
macOS mount as `bootfs` when the card is plugged into a laptop. Copy
`dev-ops/pi-gateway/printer-gateway.env.example` there as `printer-gateway.env` and fill in:

```
CUSTOMER=masala-mio
TS_AUTHKEY=tskey-auth-...
#WIFI_SSID=
#WIFI_PASSWORD=
```

On boot `printer-gateway-provision.service` connects the WLAN if given, sets the hostname,
joins the tailnet and **deletes the file**, so the auth key does not stay on the card. The file
is parsed line by line (Windows line endings and quotes are fine, it is never executed as
shell). Without network, or with a key that does not start with `tskey-auth-`, the attempt is
repeated every 30 s and the file stays on the card for correction; `journalctl -u
printer-gateway-provision` shows why. The Pi then appears as `printer-<shop>` in the
tailnet, nothing else is needed. This is the basis for a pre-built image (#47).

Nothing from a legacy order-printer installation on the same Pi (PHP, supervisor, the
repository clone) is touched; that is removed during the cutover.

### Why the bridge is bound to the device

`printer-bridge.service` has `BindsTo=dev-bondrucker.device` and is started by udev when the
printer appears. So port 9100 is open exactly while the printer is plugged in and recognised.
The order printer's health check is a plain TCP connect: with this coupling, an unplugged or
dead printer shows up as *unhealthy* on Dokploy instead of as a silently swallowed print.

That is also why the port is not published with `tailscale serve`: `tailscaled` would accept
the TCP connection itself before dialling the backend, and the health check would stay green
with no printer attached. The firewall provides the "tailnet only" property instead.

## Test print

From any admin device in the tailnet (the ACL allows admins and the Dokploy host on 9100):

```bash
PI=$(tailscale ip -4 printer-<shop>)
printf 'Testbon\n\x1dV\x42\x00' | nc -w 3 $PI 9100
```

`\x1dV\x42\x00` is ESC/POS "feed to the cutter, then cut", the same sequence the Order Printer
uses; a plain `\x1dV\x01` cuts immediately and lands a few lines too early on an Epson TM-T88.
The receipt should print and cut. From the restaurant LAN the same command against the Pi's
LAN address must time out (the firewall drops it).

**Inside the restaurant LAN, address the Pi by its tailnet IP** (or the full MagicDNS name
`printer-<shop>.<tailnet>.ts.net`). The router's DNS also knows the short name `printer-<shop>`
from DHCP and answers with the LAN address first, which the firewall drops, so `ssh
lv@printer-<shop>` hangs on site while it works from anywhere else.

Then from the Dokploy host, the check described in [tailscale.md](tailscale.md#verifying-that-containers-reach-the-tailnet),
and finally `PRINTER_DSN=tcp://<pi-ip>:9100` on the order-printer service with
`printer:test`.

## Day-to-day

```bash
tailscale ssh pi@printer-<shop>               # no SSH keys, access is governed by the ACL
sudo systemctl status printer-bridge          # active while the printer is plugged in
sudo journalctl -u printer-bridge -n 50
sudo systemctl restart printer-bridge
ls -l /dev/bondrucker                         # -> /dev/usb/lp0
sudo nft list ruleset                         # firewall
```

Re-running `install.sh` (without `TS_AUTHKEY`, the Pi is already in the tailnet) re-applies
every file and reports what it changed, useful after editing the script.

To move a Pi to another shop, or to re-test provisioning, put a new `printer-gateway.env` on
the boot partition, then leave the tailnet and reboot **in one command**, because the
Tailscale SSH session dies with the logout:

```bash
sudo sh -c 'tailscale logout; reboot'
```

## Troubleshooting

**Printer not detected.** `lsusb` must list it. If `/dev/usb/lp*` never appears (`dmesg |
grep -i usblp`), the printer does not announce USB class 07 (some cheap models use a
vendor-specific class), the `usblp` driver does not bind to it and `socat` cannot open it; such
a printer needs a different path (libusb) that this setup does not provide.

**`/dev/bondrucker` missing although the printer is on.** `ls /dev/usb/` must show an `lp*`
device; if not, see above (`usblp` did not bind). If it does: `sudo udevadm control --reload
&& sudo udevadm trigger --subsystem-match=usbmisc --action=add`, then `udevadm info -n
/dev/usb/lp0 | grep bondrucker`.

**Bridge not active.** `systemctl status printer-bridge` says why. `inactive` with the device
present: `sudo udevadm trigger --subsystem-match=usbmisc --action=add`. `failed` with
"Permission denied": the udev rule's `GROUP="lp"` did not apply, see above.

**Prints but does not cut.** The cut command differs between models; `\x1dV\x00` (full cut) or
`\x1dV\x42\x00` (feed and cut) are the alternatives. The order printer uses the library's cut,
this only concerns the manual test.

**Tailscale offline.** `tailscale status` on the Pi (via a keyboard, or LAN SSH if
`ALLOW_LAN_SSH=1` was used). `Logged out` or `NeedsLogin`: the auth key expired before this
Pi was provisioned, generate a new one and either re-run the script with `TS_AUTHKEY` and
`CUSTOMER`, or put a fresh `printer-gateway.env` on the boot partition and reboot. The
provisioning log is `journalctl -u printer-gateway-provision`. Otherwise check that the restaurant network allows outbound UDP 41641 and HTTPS;
Tailscale falls back to relays over 443, so a working WLAN is normally enough.

**Locked out.** The firewall accepts SSH only from the tailnet. With the Pi in the tailnet,
`tailscale ssh pi@printer-<shop>` works from any admin device. Without tailnet access, attach a
keyboard and screen, or mount the SD card and delete `/etc/nftables.conf` before booting.

**Port 9100 reachable from the LAN.** It must not be. `sudo nft list ruleset` should show the
`inet filter` table with `policy drop` on `input`; `systemctl status nftables` must be active.
