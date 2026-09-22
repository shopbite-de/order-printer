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

Shopping list for a new restaurant:

| Part          | Recommendation                                                                                                    |
| ------------- | ----------------------------------------------------------------------------------------------------------------- |
| Raspberry Pi  | **Raspberry Pi 4 Model B, 2 GB**: Ethernet, four USB-A ports, well cooled. Any 64-bit model (Pi 3 or newer) boots the image; Pi 1, 2 and Zero (v1) do not. A Pi Zero 2 W works but needs a USB OTG adapter and has WLAN only |
| Power supply  | the official Raspberry Pi USB-C supply (15 W); brown-outs are the most common cause of "printer sometimes offline" |
| Case          | the official case or any case with vents; no fan needed                                                           |
| microSD card  | 32 GB, an endurance card that tolerates power loss: SanDisk MAX Endurance or Samsung PRO Endurance                |
| Printer       | ESC/POS thermal printer with USB, recognised by the Linux `usblp` driver (`/dev/usb/lp0`); see the tested models  |
| Network       | Ethernet preferred, WLAN works; only outbound access is needed, no port forwarding                                |

Tested printers (`lsusb` shows `idVendor:idProduct`):

| Printer                 | USB id      | Status                                                                     |
| ----------------------- | ----------- | -------------------------------------------------------------------------- |
| Epson TM-T88 (IV/V)     | `04b8:0202` | in production at the first restaurant since 2026-09-22, cut sequence `\x1dV\x42\x00` |

The first restaurant runs on an older Pi 2/3 with 32-bit Raspbian that was set up by the script,
not from the image; the Pi 4 recommendation is the target for new restaurants.

## Prepare a Pi for a customer

Five steps, no Linux knowledge needed on site, about 15 minutes plus the copy time of the card:

1. **Flash the golden image** `pi-gateway-<version>.img.xz` (see [Golden image](#golden-image)
   for where it lives) with Raspberry Pi Imager: *Choose OS → Use custom*, pick the `.img.xz`,
   choose the card, and answer **No** when the Imager offers to apply OS customisation (it would
   overwrite the image's user and hostname settings). Or on Linux/macOS:
   `xzcat pi-gateway-<version>.img.xz | sudo dd of=/dev/sdX bs=4M status=progress conv=fsync`.
2. **Put the identity on the card.** Re-insert the card; it shows up as `bootfs`. Copy
   `printer-gateway.env.example` from that partition to `printer-gateway.env` next to it and
   fill in `CUSTOMER=<shop-slug>` and `TS_AUTHKEY=` (the reusable `tag:printer` key from the
   password manager, see [tailscale.md](tailscale.md#auth-key-for-the-pi-image)). Add
   `WIFI_SSID`/`WIFI_PASSWORD` only when the restaurant has no Ethernet. Eject the card.
3. **Boot on site (or on your desk).** Card in, printer on USB, Ethernet, power. The first boot
   takes about two minutes (the root filesystem expands, the user is created, the Pi joins the
   tailnet as `printer-<shop>` and deletes `printer-gateway.env`). It appears in the Tailscale
   admin console and in `tailscale status` on any admin device.
4. **Test print** from any admin device in the tailnet:
   `printf 'Testbon\n\x1dV\x42\x00' | nc -w 3 $(tailscale ip -4 printer-<shop>) 9100`.
5. **Point the order printer at it:** `PRINTER_DSN=tcp://<tailnet-ip>:9100` on the shop's
   order-printer service in Dokploy, redeploy, `printer:test` from the container
   ([dokploy-deployment.md](dokploy-deployment.md)).

If the Pi does not show up after five minutes: the card still carries `printer-gateway.env`
when provisioning failed (wrong key, no network); on site `journalctl -u
printer-gateway-provision` says why, see [Troubleshooting](#troubleshooting).

## Golden image

The golden image is Raspberry Pi OS Lite (64-bit) with `install.sh` applied but no customer
identity: no hostname, no tailnet login, no machine-id, no SSH host keys. Everything specific to
a shop comes from `printer-gateway.env` at first boot. What is baked in:

- the packages, udev rule, `printer-bridge`, firewall, unattended upgrades and watchdog from the
  script (see [Run the script](#run-the-script)),
- Tailscale installed and enabled, logged out,
- timezone `Europe/Berlin`, WLAN regulatory domain `DE`, `sshd` enabled (reachable only through
  the tailnet unless the firewall says otherwise),
- user `pi` with `sudo` without password, created at first boot from `userconf.txt`. The
  password is generated by the build and printed at its end; it is only needed at a keyboard or
  for LAN SSH, Tailscale SSH does not use it. Store it in the password manager with the image
  version,
- `/etc/pi-gateway-release` with the image version, the base image and the order-printer commit.

**Where it lives.** The image is about 500 MB, too big for the repository. It is kept locally
on the admin's machine in `~/workspace/shopbite/pi-images/` as `pi-gateway-<version>.img.xz`
with a `.sha256` next to it; `<version>` is the build date. Keep the last two versions.

**Build a new one** (after changes to `install.sh`, `printer-gateway-provision` or a new
Raspberry Pi OS release; the base image URL and checksums are pinned in the script). It needs
root, loop devices and an arm64 binfmt handler, so it runs on a Linux box such as the Dokploy
host, not on a laptop without `sudo`:

```bash
# on the build host (x86_64): one-off, until reboot
docker run --privileged --rm tonistiigi/binfmt --install arm64
# from the order-printer checkout
sudo OUT_DIR=/root/pi-image/out ./dev-ops/pi-gateway/build-image.sh -v $(date +%F)
```

The script downloads the pinned base image, grows it by 1 GB, runs `install.sh` inside an
arm64 chroot (the script notices the missing systemd and only installs files and enables
units), applies the settings above, clears the identity, shrinks the result with
[PiShrink](https://github.com/Drewsif/PiShrink) (pinned commit, checksum verified) and writes
`pi-gateway-<version>.img.xz` plus `.sha256`. Verify the checksum after copying it to the
admin machine. A quick test of a fresh build is step 3 to 4 above with a spare Pi.

## Manual setup without the image

Use this path for a Pi that already runs Raspberry Pi OS (the first restaurant), or when no
image is at hand.

### Flash the image

1. Raspberry Pi Imager → **Raspberry Pi OS Lite (64-bit)**.
2. In the settings (gear icon): hostname anything (the script renames it), user `pi` with a
   password, WLAN credentials if there is no Ethernet, locale `Europe/Berlin`, **enable SSH**
   with password authentication. SSH over the LAN is only needed for the first run; the
   script closes it afterwards.
3. Boot the Pi with the printer connected and switched on.

### Run the script

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

A Pi that was set up without `CUSTOMER` (or an SD card flashed from the golden image) gets its
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
tailnet, nothing else is needed. This is what the golden image relies on.

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

## Cutover log

**2026-09-22, Pizzeria La Fattoria** (first restaurant). The existing Raspberry Pi 2/3 (armhf,
Raspbian 12) that ran the Order Printer locally became the gateway while the legacy service kept
printing; `install.sh` ran next to it without touching it. Printer: Epson TM-T88 (`04b8:0202`)
as `/dev/usb/lp0`. Order of events: script, `nc` test print over the tailnet, reboot for the
watchdog (legacy supervisor came back on its own), Dokploy instance verified with `dummy://`
(`app:print-order --no-mark-in-progress` against a real order), `supervisorctl stop all` and
`autostart=false` on the Pi, `PRINTER_DSN=tcp://<pi>:9100` on the instance, `printer:test`,
test order 12993 printed once and moved to *in progress*. Legacy removal is due after three
clean days.

Learnings that went into the script and this document:

- `socat` must run with `-u`; otherwise every print logs `read(...): Bad file descriptor`.
- Right after `tailscale up` the first connection to 9100 can time out for a few seconds while
  Tailscale still negotiates the path; retry before suspecting the firewall.
- Inside the restaurant LAN the short hostname resolves to the LAN address (router DNS), which
  the firewall drops: use the tailnet IP on site.
- A manual `nc` test needs the feed-to-cutter sequence, or the cut lands too early on the TM-T88.
- Raspbian armhf works; the script is not 64-bit-only.

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
