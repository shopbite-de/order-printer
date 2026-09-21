#!/usr/bin/env bash
#
# Pi gateway: exposes the USB ESC/POS printer as raw TCP port 9100 inside the tailnet.
#
# Target: Raspberry Pi OS Lite (64-bit, Bookworm or newer). Idempotent: a second run changes
# nothing that is already in place. Nothing from a legacy order-printer installation (PHP,
# supervisor, repository clone) is touched.
#
# Everything that is the same on every Pi (packages, Tailscale, udev rule, bridge, firewall,
# updates, watchdog) is installed here. The customer identity (hostname, tailnet login) is
# applied by printer-gateway-provision, either right away when CUSTOMER and TS_AUTHKEY are
# given, or at first boot from /boot/firmware/printer-gateway.env on a flashed image.
#
#   sudo TS_AUTHKEY=tskey-auth-... CUSTOMER=<shop> ./install.sh   # set up this Pi for <shop>
#   sudo ./install.sh                                              # bake an image, provision later
#
# Variables (all optional):
#   CUSTOMER         becomes the hostname printer-<shop> (also in the tailnet)
#   TS_AUTHKEY       reusable, pre-authorized key with tag:printer; needed with CUSTOMER
#   ALLOW_LAN_SSH=1  keep port 22 open on every interface, not only in the tailnet
#
set -euo pipefail

ALLOW_LAN_SSH="${ALLOW_LAN_SSH:-0}"
REBOOT_NEEDED=0
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

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
step "Tailscale"
if ! command -v tailscale >/dev/null; then
    curl -fsSL https://tailscale.com/install.sh | sh >/dev/null
    note "tailscale installed"
fi
systemctl enable --now tailscaled >/dev/null 2>&1 || true
note "$(tailscale version | head -1), state $(tailscale status --json 2>/dev/null | jq -r '.BackendState // "unknown"')"

# ---------------------------------------------------------------------------------------------
step "USB printer -> /dev/bondrucker"
# The usblp driver creates /dev/usb/lpN in a non-stable order. There is exactly one printer per
# Pi, so every usblp device becomes /dev/bondrucker, whatever its vendor; the rule also asks
# systemd to start the bridge whenever the printer appears.
if install_file /etc/udev/rules.d/99-bondrucker.rules <<'RULE'
SUBSYSTEM=="usbmisc", KERNEL=="lp[0-9]*", SYMLINK+="bondrucker", GROUP="lp", MODE="0660", TAG+="systemd", ENV{SYSTEMD_WANTS}="printer-bridge.service"
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
step "Provisioning (hostname, tailnet login)"
[[ -f $SRC_DIR/printer-gateway-provision ]] || die "$SRC_DIR/printer-gateway-provision missing; copy the whole dev-ops/pi-gateway directory"
install_file /usr/local/sbin/printer-gateway-provision 0755 <"$SRC_DIR/printer-gateway-provision" || true
# Runs on every boot, does nothing without /boot/firmware/printer-gateway.env; a failed attempt
# (no network yet, key rejected) is retried every 30 s and the file stays until it succeeded.
install_file /etc/systemd/system/printer-gateway-provision.service <<'UNIT' || true
[Unit]
Description=First-boot provisioning of the printer gateway (hostname, tailnet)
After=network-online.target tailscaled.service
Wants=network-online.target
ConditionPathExistsGlob=/boot/*/printer-gateway.env

[Service]
Type=simple
ExecStart=/usr/local/sbin/printer-gateway-provision
Restart=on-failure
RestartSec=30s

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable printer-gateway-provision.service >/dev/null 2>&1
if [[ -n ${CUSTOMER:-} ]]; then
    CUSTOMER="$CUSTOMER" TS_AUTHKEY="${TS_AUTHKEY:?set TS_AUTHKEY together with CUSTOMER}" /usr/local/sbin/printer-gateway-provision
elif compgen -G "/boot/*/printer-gateway.env" >/dev/null; then
    systemctl start printer-gateway-provision.service
    note "started from printer-gateway.env, see: journalctl -u printer-gateway-provision"
else
    note "no CUSTOMER given: the Pi is provisioned at first boot from /boot/firmware/printer-gateway.env"
fi
HOSTNAME_NOW=$(hostname)
TS_IP=$(tailscale ip -4 2>/dev/null || echo "-")

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
note "active; SSH via 'tailscale ssh <user>@$HOSTNAME_NOW'$([[ $ALLOW_LAN_SSH == 1 ]] && echo ' and from the LAN')"

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
note "hostname     $HOSTNAME_NOW"
note "tailnet      $(tailscale status --json 2>/dev/null | jq -r '.BackendState // "?"'), IPv4 $TS_IP"
note "printer      $([[ -e /dev/bondrucker ]] && echo "/dev/bondrucker -> $(readlink -f /dev/bondrucker)" || echo 'not plugged in (/dev/bondrucker appears with the printer)')"
note "bridge       $(systemctl is-active printer-bridge.service 2>/dev/null || true) (port 9100, tailnet only)"
note "test print   printf 'Testbon\\n\\n\\n\\x1dV\\x01' | nc -w 3 $TS_IP 9100"
if [[ $REBOOT_NEEDED == 1 ]]; then
    note "REBOOT REQUIRED to activate the hardware watchdog: sudo reboot"
fi
