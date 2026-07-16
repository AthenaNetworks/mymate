#!/usr/bin/env bash
#
# Build a My Mate remote-agent .deb. The package installs the static agent binary and a
# hardened systemd unit, and - via debconf - asks for the server URL + token on install,
# writes /etc/mymate-agent/agent.env (root-only) and starts the service.
#
#   VERSION=1.2.0 agent/packaging/build-deb.sh            # -> agent/dist/mymate-agent_1.2.0_amd64.deb
#   VERSION=1.2.0 ARCH=arm64 agent/packaging/build-deb.sh # for arm64 (Pi 4/5, arm VMs)
#
# Env:
#   VERSION   package version   (default: dev)
#   ARCH      dpkg arch         (default: amd64; also arm64, armhf)
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."   # -> agent/

VERSION="${VERSION:-dev}"; VERSION="${VERSION#v}"
ARCH="${ARCH:-amd64}"
case "$ARCH" in
    amd64) GOARCH=amd64; GOARM= ;;
    arm64) GOARCH=arm64; GOARM= ;;
    armhf) GOARCH=arm;   GOARM=7 ;;
    *) echo "unsupported ARCH: $ARCH (use amd64|arm64|armhf)" >&2; exit 2 ;;
esac

MODULE="github.com/AthenaNetworks/mymate/agent"
OUT="dist"; mkdir -p "$OUT"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT

echo "==> Building agent binary ($ARCH)"
mkdir -p "$STAGE/usr/bin" "$STAGE/lib/systemd/system" "$STAGE/DEBIAN"
env CGO_ENABLED=0 GOOS=linux GOARCH="$GOARCH" ${GOARM:+GOARM=$GOARM} GOFLAGS="-buildvcs=false" \
    go build -ldflags "-s -w -X ${MODULE}/internal/agent.Version=v${VERSION}" \
    -o "$STAGE/usr/bin/mymate-agent" .
chmod 755 "$STAGE/usr/bin/mymate-agent"

echo "==> Laying systemd unit + package metadata"
# The shared unit points at /usr/local/bin; a deb installs to /usr/bin.
sed 's#/usr/local/bin/mymate-agent#/usr/bin/mymate-agent#' \
    packaging/mymate-agent.service > "$STAGE/lib/systemd/system/mymate-agent.service"

INSTALLED_KB="$(du -sk "$STAGE" | cut -f1)"
sed -e "s/@VERSION@/$VERSION/" -e "s/@ARCH@/$ARCH/" packaging/deb/DEBIAN/control > "$STAGE/DEBIAN/control"
echo "Installed-Size: $INSTALLED_KB" >> "$STAGE/DEBIAN/control"
cp packaging/deb/DEBIAN/templates "$STAGE/DEBIAN/templates"
for s in config postinst prerm postrm; do
    cp "packaging/deb/DEBIAN/$s" "$STAGE/DEBIAN/$s"
    chmod 755 "$STAGE/DEBIAN/$s"
done

DEB="$OUT/mymate-agent_${VERSION}_${ARCH}.deb"
dpkg-deb --build --root-owner-group "$STAGE" "$DEB" >/dev/null
echo "==> Done: $DEB ($(du -h "$DEB" | cut -f1))"
