#!/usr/bin/env bash
#
# Fetch the pinned cmr-ctrl release binary so build-deb.sh (and the Docker image) can bundle the
# CMR controller. cmr-ctrl is a separate Go project (github.com/AthenaNetworks/go-mikrotik-cmr)
# that publishes static linux builds; this downloads one and checks it against the checksum
# pinned in this repo (deploy/cmr/SHA256SUMS), not the one published next to it.
#
#   deploy/cmr/fetch-cmr.sh                      # -> deploy/cmr/vendor/cmr-ctrl (amd64)
#   ARCH=arm64 OUT=/tmp/cmr-ctrl deploy/cmr/fetch-cmr.sh
#
# Bumping the pin: update VERSION and SHA256SUMS together, from the release's own SHA256SUMS.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="${CMR_REPO:-https://github.com/AthenaNetworks/go-mikrotik-cmr}"
REF="${CMR_REF:-$(tr -d '[:space:]' < "$SCRIPT_DIR/VERSION")}"
ARCH="${ARCH:-amd64}"
OUT="${OUT:-$SCRIPT_DIR/vendor/cmr-ctrl}"
ASSET="cmr-ctrl-linux-${ARCH}"

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

WANT="$(awk -v a="$ASSET" '$2 == a { print $1 }' "$SCRIPT_DIR/SHA256SUMS")"
[ -n "$WANT" ] || { echo "error: no pinned checksum for $ASSET in $SCRIPT_DIR/SHA256SUMS" >&2; exit 1; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
say "Downloading $ASSET ($REF)"
curl -fsSL --retry 3 -o "$WORK/$ASSET" "$REPO/releases/download/$REF/$ASSET"

GOT="$(sha256sum "$WORK/$ASSET" | awk '{print $1}')"
if [ "$GOT" != "$WANT" ]; then
    echo "error: checksum mismatch for $ASSET: got $GOT, pinned $WANT" >&2
    exit 1
fi

mkdir -p "$(dirname "$OUT")"
install -m0755 "$WORK/$ASSET" "$OUT"
say "Fetched $OUT (sha256 ok)"
