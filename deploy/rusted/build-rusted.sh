#!/usr/bin/env bash
#
# Build the Rusted backup-engine binary so build-deb.sh (and the Docker image) can bundle
# it - backups then work out of the box instead of needing a manual install. Rusted is a
# separate Go project (github.com/JoshFinlayAU/rusted); this clones + builds it statically.
#
#   deploy/rusted/build-rusted.sh                # -> deploy/rusted/vendor/rusted
#   OUT=/tmp/rusted deploy/rusted/build-rusted.sh
#
# Needs Go (1.26+ is fetched automatically via GOTOOLCHAIN=auto) + git.
set -euo pipefail

REPO="${RUSTED_REPO:-https://github.com/JoshFinlayAU/rusted.git}"
REF="${RUSTED_REF:-main}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${OUT:-$SCRIPT_DIR/vendor/rusted}"

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

command -v go >/dev/null 2>&1 || { echo "error: Go toolchain not found (needed to build rusted)." >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "error: git not found." >&2; exit 1; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

say "Cloning rusted ($REF)"
git clone --depth 1 --branch "$REF" "$REPO" "$WORK/rusted" 2>/dev/null \
    || git clone --depth 1 "$REPO" "$WORK/rusted"

say "Building (Go auto-fetches the toolchain if needed)"
( cd "$WORK/rusted" && CGO_ENABLED=0 GOTOOLCHAIN=auto go build -trimpath -o "$WORK/rusted-bin" ./cmd/rusted )

mkdir -p "$(dirname "$OUT")"
install -m0755 "$WORK/rusted-bin" "$OUT"
say "Built $OUT ($("$OUT" --help >/dev/null 2>&1 && echo ok || echo '?'))"
