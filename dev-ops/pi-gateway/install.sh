#!/usr/bin/env bash
#
# Pi gateway: exposes the USB ESC/POS printer as raw TCP port 9100 inside the tailnet.
#
# Target: Raspberry Pi OS Lite (64-bit, Bookworm or newer). Idempotent: a second run changes
# nothing that is already in place. Nothing from a legacy order-printer installation (PHP,
# supervisor, repository clone) is touched.
#
#   sudo TS_AUTHKEY=tskey-auth-... CUSTOMER=<shop> ./install.sh
#
# Variables:
#   CUSTOMER         required, becomes the hostname printer-<shop> (also in the tailnet)
#   TS_AUTHKEY       required on the first run (reusable, pre-authorized key with tag:printer)
#   PRINTER_VENDOR   USB idVendor of the printer (4 hex digits); auto-detected when unset
#   PRINTER_PRODUCT  USB idProduct of the printer (4 hex digits); auto-detected when unset
#   ALLOW_LAN_SSH=1  keep port 22 open on every interface, not only in the tailnet
#
set -euo pipefail

: "${CUSTOMER:?set CUSTOMER=<shop slug>, it becomes the hostname printer-<shop>}"
CUSTOMER=${CUSTOMER//[^a-z0-9-]/-}
HOSTNAME_WANTED="printer-${CUSTOMER}"
PRINTER_VENDOR="${PRINTER_VENDOR:-}"
PRINTER_PRODUCT="${PRINTER_PRODUCT:-}"
ALLOW_LAN_SSH="${ALLOW_LAN_SSH:-0}"
REBOOT_NEEDED=0

step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }

# Writes stdin to $1 only when the content differs. Returns 0 when the file changed.
install_file() {
    local target=$1 tmp
    tmp=$(mktemp)
    cat >"$tmp"
    if [[ -f $target ]] && cmp -s "$tmp" "$target"; then
        rm -f "$tmp"
        note "$target unchanged"
        return 1
    fi
    install -m "${2:-0644}" -D "$tmp" "$target"
    rm -f "$tmp"
    note "$target written"
    return 0
}

[[ $EUID -eq 0 ]] || die "run as root: sudo TS_AUTHKEY=... CUSTOMER=... $0"
[[ -e /etc/os-release ]] && grep -q '^ID=\(debian\|raspbian\)' /etc/os-release || die "this is not Raspberry Pi OS / Debian"

# ---------------------------------------------------------------------------------------------
step "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends socat nftables unattended-upgrades curl jq >/dev/null
note "socat, nftables, unattended-upgrades, curl, jq installed"

# ---------------------------------------------------------------------------------------------
step "Hostname $HOSTNAME_WANTED"
if [[ $(hostname) != "$HOSTNAME_WANTED" ]]; then
    hostnamectl set-hostname "$HOSTNAME_WANTED"
    sed -i "s/^127\.0\.1\.1\s.*/127.0.1.1\t$HOSTNAME_WANTED/" /etc/hosts
    grep -q "^127.0.1.1" /etc/hosts || printf '127.0.1.1\t%s\n' "$HOSTNAME_WANTED" >>/etc/hosts
    note "hostname set"
else
    note "already set"
fi

# ---------------------------------------------------------------------------------------------
step "Tailscale"
if ! command -v tailscale >/dev/null; then
    curl -fsSL https://tailscale.com/install.sh | sh >/dev/null
    note "tailscale installed"
fi
systemctl enable --now tailscaled >/dev/null 2>&1 || true

ts_json=$(tailscale status --json 2>/dev/null || echo '{}')
ts_state=$(jq -r '.BackendState // "NoState"' <<<"$ts_json")
ts_tags=$(jq -r '.Self.Tags // [] | join(",")' <<<"$ts_json")
ts_host=$(jq -r '.Self.HostName // ""' <<<"$ts_json")
if [[ $ts_state == Running && $ts_tags == *tag:printer* && $ts_host == "$HOSTNAME_WANTED" ]]; then
    note "already in the tailnet as $ts_host ($ts_tags)"
else
    : "${TS_AUTHKEY:?set TS_AUTHKEY=tskey-auth-... (reusable, pre-authorized, tag:printer)}"
    tailscale up --reset --ssh --accept-dns=false \
        --advertise-tags=tag:printer \
        --hostname="$HOSTNAME_WANTED" \
        --auth-key="$TS_AUTHKEY"
    note "joined the tailnet"
fi
tailscale set --auto-update >/dev/null 2>&1 || true
TS_IP=$(tailscale ip -4 2>/dev/null || echo "?")
note "tailnet IPv4: $TS_IP"

# ---------------------------------------------------------------------------------------------
step "USB printer"
if [[ -z $PRINTER_VENDOR || -z $PRINTER_PRODUCT ]]; then
    # Every USB interface of class 07 (printer); walk up to the device for its ids.
    mapfile -t found < <(
        for cls in /sys/bus/usb/devices/*/bInterfaceClass; do
            [[ -r $cls && $(<"$cls") == 07 ]] || continue
            dev=$(dirname "$cls")
            dev=${dev%%:*}
            printf '%s:%s\n' "$(<"$dev/idVendor")" "$(<"$dev/idProduct")"
        done | sort -u
    )
    case ${#found[@]} in
        0) die "no USB printer found; connect and switch it on, or set PRINTER_VENDOR and PRINTER_PRODUCT (see lsusb)" ;;
        1) PRINTER_VENDOR=${found[0]%%:*}; PRINTER_PRODUCT=${found[0]##*:}; note "detected ${found[0]}" ;;
        *) die "several USB printers found (${found[*]}); set PRINTER_VENDOR and PRINTER_PRODUCT" ;;
    esac
fi
[[ $PRINTER_VENDOR =~ ^[0-9a-f]{4}$ && $PRINTER_PRODUCT =~ ^[0-9a-f]{4}$ ]] \
    || die "PRINTER_VENDOR/PRINTER_PRODUCT must be 4 lowercase hex digits each (got $PRINTER_VENDOR:$PRINTER_PRODUCT)"

# The usblp driver creates /dev/usb/lpN in a non-stable order. The rule links the printer
# to /dev/bondrucker by its ids and asks systemd to start the bridge whenever it appears.
if install_file /etc/udev/rules.d/99-bondrucker.rules <<RULE
SUBSYSTEM=="usbmisc", KERNEL=="lp[0-9]*", ATTRS{idVendor}=="$PRINTER_VENDOR", ATTRS{idProduct}=="$PRINTER_PRODUCT", SYMLINK+="bondrucker", GROUP="lp", MODE="0660", TAG+="systemd", ENV{SYSTEMD_WANTS}="printer-bridge.service"
RULE
then
    udevadm control --reload
fi

# ---------------------------------------------------------------------------------------------
step "printer-bridge.service (socat, port 9100)"
# BindsTo: the service runs only while the printer is plugged in, so port 9100 is open exactly
# when a print can succeed. The order printer's health check (a TCP connect) then tells the truth.
install_file /etc/systemd/system/printer-bridge.service <<'UNIT' || true
[Unit]
Description=Raw TCP port 9100 to the USB receipt printer
BindsTo=dev-bondrucker.device
After=dev-bondrucker.device tailscaled.service
StartLimitIntervalSec=0

[Service]
ExecStart=/usr/bin/socat TCP-LISTEN:9100,reuseaddr,fork OPEN:/dev/bondrucker,wronly
DynamicUser=yes
SupplementaryGroups=lp
Restart=always
RestartSec=2
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
UNIT
systemctl daemon-reload
udevadm trigger --subsystem-match=usbmisc --action=add
sleep 1
if [[ -e /dev/bondrucker ]]; then
    systemctl is-active --quiet printer-bridge.service && note "bridge running, /dev/bondrucker -> $(readlink -f /dev/bondrucker)" \
        || note "WARNING: /dev/bondrucker exists but printer-bridge.service is not active: journalctl -u printer-bridge"
else
    note "printer not present right now; the bridge starts when it is plugged in"
fi

# ---------------------------------------------------------------------------------------------
step "Firewall (inbound only via tailscale0)"
lan_ssh_rule=""
[[ $ALLOW_LAN_SSH == 1 ]] && lan_ssh_rule='        tcp dport 22 accept comment "ALLOW_LAN_SSH=1"'
# The table is flushed and rebuilt by name so that Tailscale's own nft tables survive a reload.
if install_file /etc/nftables.conf <<RULES 0755
#!/usr/sbin/nft -f
# Managed by dev-ops/pi-gateway/install.sh (order-printer). Inbound only from the tailnet.
table inet filter {}
flush table inet filter
table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;
        iif lo accept
        ct state established,related accept
        ct state invalid drop
        iifname "tailscale0" accept
        meta l4proto icmp accept
        meta l4proto ipv6-icmp accept
        udp dport 68 accept comment "DHCP client"
        udp dport 41641 accept comment "Tailscale direct connections"
${lan_ssh_rule}
    }
    chain forward {
        type filter hook forward priority 0; policy drop;
    }
    chain output {
        type filter hook output priority 0; policy accept;
    }
}
RULES
then
    nft -f /etc/nftables.conf
fi
systemctl enable --now nftables >/dev/null 2>&1
note "active; SSH via 'tailscale ssh pi@$HOSTNAME_WANTED'$([[ $ALLOW_LAN_SSH == 1 ]] && echo ' and from the LAN')"

# ---------------------------------------------------------------------------------------------
step "Unattended security updates, reboot at 04:30 when required"
install_file /etc/apt/apt.conf.d/20auto-upgrades <<'CONF' || true
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF
install_file /etc/apt/apt.conf.d/52pi-gateway <<'CONF' || true
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-WithUsers "true";
Unattended-Upgrade::Automatic-Reboot-Time "04:30";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
CONF
systemctl enable --now apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1

# ---------------------------------------------------------------------------------------------
step "Hardware watchdog"
config_txt=/boot/firmware/config.txt
[[ -f $config_txt ]] || config_txt=/boot/config.txt
if [[ -f $config_txt ]]; then
    if ! grep -q '^dtparam=watchdog=on' "$config_txt"; then
        printf '\n# order-printer pi-gateway: hardware watchdog\ndtparam=watchdog=on\n' >>"$config_txt"
        note "$config_txt: dtparam=watchdog=on added"
        REBOOT_NEEDED=1
    else
        note "$config_txt already enables the watchdog"
    fi
else
    note "WARNING: no config.txt found, watchdog not enabled"
fi
# systemd pets /dev/watchdog itself; the BCM watchdog allows at most 15 s.
if install_file /etc/systemd/system.conf.d/watchdog.conf <<'CONF'
[Manager]
RuntimeWatchdogSec=15
RebootWatchdogSec=2min
CONF
then
    systemctl daemon-reexec
fi
[[ -e /dev/watchdog ]] || REBOOT_NEEDED=1

# ---------------------------------------------------------------------------------------------
step "Done"
note "hostname     $HOSTNAME_WANTED"
note "tailnet IP   $TS_IP"
note "printer      usb $PRINTER_VENDOR:$PRINTER_PRODUCT -> /dev/bondrucker"
note "bridge       $(systemctl is-active printer-bridge.service 2>/dev/null || true) (port 9100, tailnet only)"
note "test print   printf 'Testbon\\n\\n\\n\\x1dV\\x01' | nc -w 3 $TS_IP 9100"
if [[ $REBOOT_NEEDED == 1 ]]; then
    note "REBOOT REQUIRED to activate the hardware watchdog: sudo reboot"
fi
