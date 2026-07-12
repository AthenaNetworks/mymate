#!/usr/bin/env bash
#
# Build a Proxmox-ready LXC container template of My Mate.
#
# Produces dist/mymate_<ver>_<distro>_amd64.tar.zst - a full root filesystem with My Mate
# already installed (from the .deb) and set to self-provision on first boot. Drop it into a
# Proxmox host's template store and `pct create` a container: it comes up, gives itself
# fresh secrets + the right hostname, and serves the console - no manual setup.
#
# Requires: the .deb already built (deploy/build/build-deb.sh) + podman (rootful) + zstd.
#
#   deploy/build/build-lxc.sh
#   BASE=ubuntu:24.04 deploy/build/build-lxc.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DIST="$REPO_ROOT/dist"
VERSION="${VERSION:-$(cat "$SCRIPT_DIR/VERSION" 2>/dev/null || echo 1.0.0)}"
BASE="${BASE:-debian:13}"
DISTRO_TAG="$(echo "$BASE" | tr ':/' '--')"
NAME="mmlxc-build"
IMG="mm-systemd-lxcbuild"

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }
die() { printf '\033[31mxx \033[0m %s\n' "$*" >&2; exit 1; }

command -v podman >/dev/null 2>&1 || die "podman is required to build the LXC rootfs."
command -v zstd   >/dev/null 2>&1 || die "zstd is required (apt-get install zstd)."
DEB="$(ls -t "$DIST"/mymate_*.deb 2>/dev/null | head -1 || true)"
[ -n "$DEB" ] && [ -f "$DEB" ] || die "No .deb in dist/ - run deploy/build/build-deb.sh first."

PODMAN="sudo podman"

# --- 1. a systemd-enabled base image -------------------------------------
say "Preparing a systemd $BASE build image"
cat > /tmp/Cf.lxc <<EOF
FROM $BASE
ENV DEBIAN_FRONTEND=noninteractive container=lxc
# Minimal appliance base: systemd + networking (ifupdown, for Proxmox's DHCP injection).
# No SSH - Proxmox gives console access via `pct enter`; add openssh-server if you want it.
RUN apt-get update -qq && apt-get install -y --no-install-recommends \
      systemd systemd-sysv dbus curl ca-certificates procps sudo iproute2 ifupdown \
      >/dev/null 2>&1 && apt-get clean
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
EOF
$PODMAN build -t "$IMG" -f /tmp/Cf.lxc /tmp >/dev/null

# --- 2. boot it + install My Mate ----------------------------------------
say "Installing My Mate into the rootfs"
$PODMAN rm -f "$NAME" >/dev/null 2>&1 || true
$PODMAN run -d --name "$NAME" --privileged --systemd=always -v "$DIST:/dist:ro" "$IMG" >/dev/null
sleep 6
$PODMAN exec "$NAME" bash /dist/install.sh >/dev/null 2>&1 || die "in-container install failed"

# --- 3. templatise: strip instance identity, arm first-boot --------------
say "Templatising (fresh identity on first boot)"
$PODMAN exec "$NAME" bash -c '
    set -e
    systemctl stop mymate-loop mymate-reverb mymate-horizon mymate-scheduler nginx php8.4-fpm 2>/dev/null || true
    # First-boot provisioning re-runs on the customer host: remove the marker, enable it.
    rm -f /opt/mymate/storage/.provisioned
    systemctl enable mymate-firstboot >/dev/null 2>&1 || true
    # Neutralise per-host state so every clone is unique.
    : > /etc/machine-id; rm -f /var/lib/dbus/machine-id
    rm -f /etc/ssh/ssh_host_* 2>/dev/null || true
    # Trim the image: logs, apt cache, engine logs.
    find /var/log -type f -exec truncate -s 0 {} + 2>/dev/null || true
    rm -rf /opt/mymate/storage/logs/*.log 2>/dev/null || true
    apt-get clean; rm -rf /var/lib/apt/lists/*
    # Blank the auth-host lines so first-boot sets them for the real host (secrets too).
    sed -i "s|^APP_URL=.*|APP_URL=http://localhost|" /opt/mymate/.env 2>/dev/null || true
    # Strip the build-host self-signed cert (per-instance - first-boot regenerates it) and
    # revert nginx to the plain :80 site so the template boots cleanly on HTTP before
    # first-boot flips it back to self-signed HTTPS for the real host.
    rm -rf /etc/mymate/tls 2>/dev/null || true
    cp /usr/share/mymate/nginx-mymate.conf /etc/nginx/sites-available/mymate 2>/dev/null || true
'

# --- 4. export the rootfs as a Proxmox template --------------------------
say "Exporting root filesystem"
$PODMAN stop -t 5 "$NAME" >/dev/null 2>&1 || true
mkdir -p "$DIST"
OUT="$DIST/mymate_${VERSION}_${DISTRO_TAG}_amd64.tar.zst"
$PODMAN export "$NAME" | zstd -q -19 -T0 -o "$OUT" -f
$PODMAN rm -f "$NAME" >/dev/null 2>&1 || true

# Proxmox reads ownership from the tar; podman export already writes root-owned entries.
say "Done."
ls -lh "$OUT"
echo
cat <<EOF
Load into Proxmox (on the Proxmox host):
  1. copy $(basename "$OUT") to  /var/lib/vz/template/cache/
  2. create + start a container (unprivileged, DHCP):
       pct create <VMID> local:vztmpl/$(basename "$OUT") \\
         --hostname mymate --cores 2 --memory 2048 --swap 512 \\
         --net0 name=eth0,bridge=vmbr0,ip=dhcp \\
         --features nesting=1 --unprivileged 1 --onboot 1 --start 1
  3. browse http://<container-ip>  ->  create your admin:
       pct exec <VMID> -- sudo -u mymate php /opt/mymate/artisan mymate:user:create --admin
EOF
