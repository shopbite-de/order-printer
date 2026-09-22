#!/usr/bin/env bash
#
# Builds the Pi gateway golden image: Raspberry Pi OS Lite (64-bit) with install.sh applied but
# without a customer identity. A flashed card provisions itself at first boot from
# printer-gateway.env on the boot partition (see docs/pi-gateway.md).
#
#   sudo ./build-image.sh [-o <outdir>] [-v <version>]
#
# Needs root (loop devices, chroot), an x86_64 or arm64 Linux with losetup, parted, e2fsprogs,
# xz, curl, openssl, and for x86_64 an arm64 binfmt handler:
#   docker run --privileged --rm tonistiigi/binfmt --install arm64
#
# Variables (all optional):
#   PI_PASSWORD   password of the local user "pi" (console, LAN SSH with ALLOW_LAN_SSH); a random
#                 one is generated and printed at the end when unset. Tailscale SSH needs none.
#   WLAN_COUNTRY  regulatory domain, default DE
#   TIMEZONE      default Europe/Berlin
#   IMAGE_GROW_MB extra space for the packages during the build, default 1024; shrunk afterwards
#
# Output: <outdir>/pi-gateway-<version>.img.xz plus .sha256; the work directory keeps the
# downloaded base image for the next build.
#
set -euo pipefail

BASE_IMAGE_URL="https://downloads.raspberrypi.com/raspios_lite_arm64/images/raspios_lite_arm64-2026-09-15/2026-09-15-raspios-trixie-arm64-lite.img.xz"
BASE_IMAGE_SHA256="cdf4f3bfac35ae947b46e4e767f935453810549779ac3290e05a6754aee627e5"
PISHRINK_URL="https://raw.githubusercontent.com/Drewsif/PiShrink/5f358d03eed4b7334657ee93867826a2b42f112a/pishrink.sh"
PISHRINK_SHA256="71026f0c02ac099e588a3eb8f70760c1b680aa8ea3acde61a0141fbaeb68c777"

OUT_DIR=${OUT_DIR:-$PWD}
VERSION=${VERSION:-$(date +%Y-%m-%d)}
WLAN_COUNTRY=${WLAN_COUNTRY:-DE}
TIMEZONE=${TIMEZONE:-Europe/Berlin}
IMAGE_GROW_MB=${IMAGE_GROW_MB:-1024}
PI_USER=pi
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

while getopts ":o:v:h" opt; do
    case $opt in
        o) OUT_DIR=$OPTARG ;;
        v) VERSION=$OPTARG ;;
        *) sed -n '2,22p' "$0"; exit 1 ;;
    esac
done

step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die()  { printf 'error: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "run as root"
for tool in losetup parted e2fsck resize2fs xz curl sha256sum openssl truncate; do
    command -v "$tool" >/dev/null || die "$tool missing"
done
if [[ $(uname -m) != aarch64 ]]; then
    grep -qs enabled /proc/sys/fs/binfmt_misc/qemu-aarch64 \
        || die "no arm64 binfmt handler: docker run --privileged --rm tonistiigi/binfmt --install arm64"
fi
[[ -f $SRC_DIR/install.sh && -f $SRC_DIR/printer-gateway-provision ]] || die "run from dev-ops/pi-gateway of the order-printer checkout"

WORK_DIR=${WORK_DIR:-$OUT_DIR/work}
mkdir -p "$OUT_DIR" "$WORK_DIR"
BASE_XZ=$WORK_DIR/$(basename "$BASE_IMAGE_URL")
IMG=$WORK_DIR/pi-gateway-$VERSION.img
MNT=$WORK_DIR/mnt
OUT_IMG=$OUT_DIR/pi-gateway-$VERSION.img
LOOP=""

cleanup() {
    set +e
    if mountpoint -q "$MNT"; then
        for d in dev/pts dev proc sys boot/firmware; do mountpoint -q "$MNT/$d" && umount "$MNT/$d"; done
        umount "$MNT"
    fi
    [[ -n $LOOP ]] && losetup -d "$LOOP"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------------------------
step "Base image"
if [[ ! -f $BASE_XZ ]]; then
    curl -fsSL -o "$BASE_XZ" "$BASE_IMAGE_URL"
    note "downloaded $(basename "$BASE_XZ")"
fi
echo "$BASE_IMAGE_SHA256  $BASE_XZ" | sha256sum -c --quiet - || die "checksum mismatch for $BASE_XZ"
note "checksum ok"
rm -f "$IMG"
xz -dkc "$BASE_XZ" >"$IMG"
truncate -s "+${IMAGE_GROW_MB}M" "$IMG"
parted -s "$IMG" resizepart 2 100%
LOOP=$(losetup -fP --show "$IMG")
e2fsck -fp "${LOOP}p2" >/dev/null
resize2fs "${LOOP}p2" >/dev/null 2>&1
note "root partition grown by ${IMAGE_GROW_MB} MB on $LOOP"

# ---------------------------------------------------------------------------------------------
step "Mount and enter"
mkdir -p "$MNT"
mount "${LOOP}p2" "$MNT"
mount "${LOOP}p1" "$MNT/boot/firmware"
mount -t proc proc "$MNT/proc"
mount --bind /sys "$MNT/sys"
mount --bind /dev "$MNT/dev"
mount --bind /dev/pts "$MNT/dev/pts"
# Name resolution inside the chroot; the image's own file is restored below.
cp -a "$MNT/etc/resolv.conf" "$WORK_DIR/resolv.conf.image"
cp -L /etc/resolv.conf "$MNT/etc/resolv.conf"
# Keep package postinst scripts from starting services.
printf '#!/bin/sh\nexit 101\n' >"$MNT/usr/sbin/policy-rc.d"
chmod 755 "$MNT/usr/sbin/policy-rc.d"
rm -rf "$MNT/tmp/pi-gateway"
cp -a "$SRC_DIR" "$MNT/tmp/pi-gateway"
run_in_image() { chroot "$MNT" /usr/bin/env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 DEBIAN_FRONTEND=noninteractive "$@"; }
note "chroot works: $(run_in_image uname -m)"

# ---------------------------------------------------------------------------------------------
step "install.sh inside the image"
run_in_image bash /tmp/pi-gateway/install.sh

# ---------------------------------------------------------------------------------------------
step "Image-wide settings"
run_in_image raspi-config nonint do_change_timezone "$TIMEZONE" >/dev/null 2>&1 || true
note "timezone $(run_in_image cat /etc/timezone)"
# raspi-config writes the regulatory domain into cmdline.txt and unblocks wifi at boot.
run_in_image raspi-config nonint do_wifi_country "$WLAN_COUNTRY" >/dev/null 2>&1 || true
grep -q "cfg80211.ieee80211_regdom=$WLAN_COUNTRY" "$MNT/boot/firmware/cmdline.txt" \
    || sed -i "1s/\$/ cfg80211.ieee80211_regdom=$WLAN_COUNTRY/" "$MNT/boot/firmware/cmdline.txt"
note "WLAN country $WLAN_COUNTRY"
# User "pi" is created at first boot by userconfig.service from userconf.txt; sudo without a
# password, as on cards written by Raspberry Pi Imager.
if [[ -z ${PI_PASSWORD:-} ]]; then
    PI_PASSWORD=$(openssl rand -base64 12 | tr -d '/+=' | head -c 14)
    PASSWORD_GENERATED=1
fi
printf '%s:%s\n' "$PI_USER" "$(openssl passwd -6 "$PI_PASSWORD")" >"$MNT/boot/firmware/userconf.txt"
printf '%s ALL=(ALL) NOPASSWD: ALL\n' "$PI_USER" >"$MNT/etc/sudoers.d/010_pi-nopasswd"
chmod 440 "$MNT/etc/sudoers.d/010_pi-nopasswd"
# sshd for the LAN case (ALLOW_LAN_SSH); the firewall keeps port 22 tailnet-only otherwise.
touch "$MNT/boot/firmware/ssh"
note "user $PI_USER, sshd enabled at first boot"
cp "$SRC_DIR/printer-gateway.env.example" "$MNT/boot/firmware/printer-gateway.env.example"
git_rev=$(git -C "$SRC_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)
cat >"$MNT/etc/pi-gateway-release" <<REL
PI_GATEWAY_VERSION=$VERSION
PI_GATEWAY_BASE=$(basename "$BASE_XZ" .img.xz)
PI_GATEWAY_COMMIT=$git_rev
PI_GATEWAY_BUILT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
REL
note "/etc/pi-gateway-release written (order-printer $git_rev)"

# ---------------------------------------------------------------------------------------------
step "Clean up the image"
run_in_image apt-get clean
rm -rf "$MNT/var/lib/apt/lists/"* "$MNT/tmp/pi-gateway" "$MNT/usr/sbin/policy-rc.d" "$MNT/root/.bash_history"
cp -a "$WORK_DIR/resolv.conf.image" "$MNT/etc/resolv.conf"
# Fresh identity on first boot: no machine-id, no SSH host keys, no tailnet state.
printf 'uninitialized\n' >"$MNT/etc/machine-id"
rm -f "$MNT/etc/ssh/ssh_host_"* "$MNT/var/lib/tailscale/tailscaled.state"
[[ -e $MNT/var/lib/tailscale/tailscaled.state ]] && die "tailscale state present, the image must not carry an identity"
note "apt caches, machine-id, host keys cleared"
df -h "$MNT" | tail -1 | awk '{print "    root filesystem used " $3 " of " $2}'
cleanup
trap - EXIT
LOOP=""

# ---------------------------------------------------------------------------------------------
step "Shrink and compress"
PISHRINK=$WORK_DIR/pishrink.sh
if [[ ! -f $PISHRINK ]] || ! echo "$PISHRINK_SHA256  $PISHRINK" | sha256sum -c --quiet - 2>/dev/null; then
    curl -fsSL -o "$PISHRINK" "$PISHRINK_URL"
    echo "$PISHRINK_SHA256  $PISHRINK" | sha256sum -c --quiet - || die "checksum mismatch for pishrink.sh"
    chmod 755 "$PISHRINK"
fi
rm -f "$OUT_IMG" "$OUT_IMG.xz"
"$PISHRINK" -aZn "$IMG" "$OUT_IMG" >"$WORK_DIR/pishrink.log" 2>&1 || { tail -20 "$WORK_DIR/pishrink.log"; die "pishrink failed"; }
[[ -f $OUT_IMG.xz ]] || die "pishrink produced no $OUT_IMG.xz"
rm -f "$IMG"
(cd "$OUT_DIR" && sha256sum "$(basename "$OUT_IMG.xz")" >"$(basename "$OUT_IMG.xz").sha256")

# ---------------------------------------------------------------------------------------------
step "Done"
note "image     $OUT_IMG.xz ($(du -h "$OUT_IMG.xz" | cut -f1))"
note "checksum  $(cut -d' ' -f1 "$OUT_IMG.xz.sha256")"
note "version   $VERSION (order-printer $git_rev, base $(basename "$BASE_XZ" .img.xz))"
if [[ ${PASSWORD_GENERATED:-0} == 1 ]]; then
    note "password  of user $PI_USER: $PI_PASSWORD  (store it in the password manager; console and LAN SSH only)"
fi
