#!/usr/bin/env bash
#
# Build fping >= 5.5 from source. Debian 13 still ships fping 5.2,
# which lacks the `--json` output FpingRunner parses - so the package bundles a
# from-source 5.5 binary. This produces it at $OUT (default /usr/local/sbin/fping) for
# build-deb.sh to pick up (FPING_BIN).
#
#   deploy/build/build-fping.sh                 # -> /usr/local/sbin/fping
#   OUT=/tmp/fping deploy/build/build-fping.sh
#
set -euo pipefail

VERSION="${FPING_VERSION:-5.5}"
OUT="${OUT:-/usr/local/sbin/fping}"
NO_APT="${NO_APT:-0}"

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

if [ "$NO_APT" != "1" ]; then
    SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"
    export DEBIAN_FRONTEND=noninteractive
    $SUDO apt-get update -qq
    $SUDO apt-get install -y --no-install-recommends \
        build-essential autoconf automake libtool ca-certificates curl
fi

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
cd "$WORK"

say "Fetching fping $VERSION"
curl -fsSL "https://fping.org/dist/fping-${VERSION}.tar.gz" -o fping.tar.gz
tar xzf fping.tar.gz
cd "fping-${VERSION}"

say "Building"
./configure --prefix=/usr/local --enable-ipv4 --enable-ipv6 >/dev/null
make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)" >/dev/null

SUDO=""; [ "$(id -u)" -ne 0 ] && [ "$OUT" = "/usr/local/sbin/fping" ] && SUDO="sudo"
mkdir -p "$(dirname "$OUT")" 2>/dev/null || $SUDO mkdir -p "$(dirname "$OUT")"
$SUDO install -m755 src/fping "$OUT"

say "fping installed: $OUT ($("$OUT" -v 2>&1 | head -1))"
