#!/usr/bin/env bash
#
# Build a distributable My Mate .deb.
#
# Produces dist/mymate_<version>_amd64.deb - a self-contained package that installs the
# app, pulls in every system dependency, and runs a postinst that provisions
# Postgres/Redis/nginx/php-fpm + systemd services, generates secrets, migrates, and leaves
# a working install. Free and open source - no device limits, nothing to unlock.
#
# Usage:
#   deploy/build/build-deb.sh
#   VERSION=1.2.0 deploy/build/build-deb.sh
#
# Env knobs:
#   VERSION      package version   (default: read from deploy/build/VERSION)
#   ARCH         dpkg arch         (default: amd64)
#   FPING_BIN    fping >= 5.5      (default: deploy/build/vendor/fping, then /usr/local/sbin/fping)
#   REVERB_KEY   public WS app key baked into the SPA build (default: mymate)
#   PHP_VERSION  target PHP        (default: 8.4)
#
set -euo pipefail

# --- Paths ----------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PKG_DIR="$SCRIPT_DIR/package"
FILES_DIR="$SCRIPT_DIR/files"

VERSION="${VERSION:-$(cat "$SCRIPT_DIR/VERSION" 2>/dev/null || echo 1.0.0)}"
ARCH="${ARCH:-amd64}"
# The fping 5.5 (JSON-output) binary FpingRunner needs. Ships vendored in the repo
# (Debian 13's own fping is 5.2, no --json) so a clean checkout/CI build is self-contained;
# regenerate with build-fping.sh. Falls back to a system build if the vendored one is absent.
FPING_BIN="${FPING_BIN:-$SCRIPT_DIR/vendor/fping}"
[ -f "$FPING_BIN" ] || FPING_BIN=/usr/local/sbin/fping
# Optional Rusted backup-engine binary. When present it's bundled to /usr/local/bin/rusted
# and postinst auto-provisions backups; when absent the package still installs fine (backups
# just start unconfigured). Build it with deploy/rusted/build-rusted.sh.
RUSTED_BIN="${RUSTED_BIN:-$REPO_ROOT/deploy/rusted/vendor/rusted}"
REVERB_KEY="${REVERB_KEY:-mymate}"
PHP_VERSION="${PHP_VERSION:-8.4}"

INSTALL_PREFIX="/opt/mymate"          # where the app lands on the target
BUILD="$REPO_ROOT/build/deb"          # scratch build root (git-ignored)
STAGE="$BUILD/stage"                  # the package filesystem (becomes the .deb payload)
DIST="$REPO_ROOT/dist"                # output artifacts

say()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33m!! \033[0m %s\n' "$*" >&2; }
die()  { printf '\033[31mxx \033[0m %s\n' "$*" >&2; exit 1; }

# --- Preflight ------------------------------------------------------------
say "My Mate .deb builder - version $VERSION ($ARCH)"

for t in php composer npm dpkg-deb tar; do
    command -v "$t" >/dev/null 2>&1 || die "missing required tool: $t"
done
[ -f "$FPING_BIN" ] || die "fping >= 5.5 not found (looked in deploy/build/vendor/fping and /usr/local/sbin/fping). Run deploy/build/build-fping.sh, or set FPING_BIN=/path/to/fping."

# --- Clean ----------------------------------------------------------------
say "Cleaning $BUILD"
rm -rf "$BUILD"
mkdir -p "$STAGE$INSTALL_PREFIX" "$DIST"

# --- 1. Mirror the app source into the stage ------------------------------
# Only what the app needs at runtime. vendor/ + public/build are (re)built below;
# everything dev-only, secret, or environment-specific is excluded. tar (not rsync)
# so the build host needs nothing beyond coreutils.
say "Staging application source"
APP="$STAGE$INSTALL_PREFIX"
tar -C "$REPO_ROOT" \
    --exclude='./.git' \
    --exclude='./node_modules' \
    --exclude='./tests' \
    --exclude='./build' \
    --exclude='./dist' \
    --exclude='./deploy/build' \
    --exclude='./deploy/rusted/vendor' \
    --exclude='./vendor' \
    --exclude='./agent' \
    --exclude='./PRD.md' \
    --exclude='./database/SCHEMA.md' \
    --exclude='./context' \
    --exclude='./docs' \
    --exclude='./resources/js' \
    --exclude='./resources/css' \
    --exclude='./public/build' \
    --exclude='./public/hot' \
    --exclude='./public/downloads' \
    --exclude='./.env' \
    --exclude='./.env.*' \
    --exclude='./CREDS' \
    --exclude='./scripts/*.db' \
    --exclude='./scripts/export' \
    --exclude='./scripts/__pycache__' \
    --exclude='./storage/logs/*' \
    --exclude='./storage/framework/cache/data/*' \
    --exclude='./storage/framework/sessions/*' \
    --exclude='./storage/framework/views/*' \
    -cf - . | tar -C "$APP" -xf -

# Keep the storage/bootstrap-cache skeleton (tar dropped their contents).
mkdir -p "$APP/storage/logs" \
         "$APP/storage/framework/cache/data" \
         "$APP/storage/framework/sessions" \
         "$APP/storage/framework/views" \
         "$APP/bootstrap/cache"
find "$APP/storage" "$APP/bootstrap/cache" -type d -exec touch {}/.gitignore \; 2>/dev/null || true

# Stamp the built version so the app (and its update check) knows what it is.
echo "$VERSION" > "$APP/VERSION"

# --- 2. Composer (production, no dev) -------------------------------------
say "Installing PHP dependencies (--no-dev)"
# Drop any framework caches carried over from the dev tree BEFORE composer runs. A stale
# bootstrap/cache/packages.php still lists dev providers (Pail, Pint, ...) that --no-dev
# removes -> the app fatals on boot.
rm -f "$APP"/bootstrap/cache/*.php
composer install \
    --working-dir="$APP" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
    --quiet
# Regenerate the package manifest (packages.php + services.php) against the --no-dev vendor.
# Deliberately NO config:/route:/view: cache - the app reads .env per request so a single
# image works on any host, and there are no stale cached configs to debug.
php "$APP/artisan" package:discover --ansi >/dev/null 2>&1 || die "package:discover failed on the --no-dev tree"

# --- 3. Frontend assets (baked with the shared public WS key) -------------
# Built into the STAGE only (never the live repo). VITE_REVERB_APP_KEY is the public
# Pusher-protocol app key - safe to share across installs; channel auth uses the
# per-install REVERB_APP_SECRET, generated in postinst.
say "Building frontend assets"
( cd "$REPO_ROOT" && VITE_REVERB_APP_KEY="$REVERB_KEY" \
    npx vite build --outDir "$APP/public/build" --emptyOutDir >/dev/null )

# --- 4. System files into the stage --------------------------------------
say "Laying system files (systemd, nginx, php-fpm, fping)"
install -Dm755 "$FPING_BIN"                       "$STAGE/usr/local/sbin/fping"
# Rusted backup engine (optional) - postinst runs deploy/rusted/provision.sh to wire it up.
if [ -f "$RUSTED_BIN" ]; then
    install -Dm755 "$RUSTED_BIN"                  "$STAGE/usr/local/bin/rusted"
    say "Bundled the Rusted backup engine"
else
    warn "Rusted binary not found ($RUSTED_BIN) - backups will need manual setup. Build it with deploy/rusted/build-rusted.sh."
fi
install -Dm644 "$FILES_DIR/nginx-mymate.conf"     "$STAGE/etc/nginx/sites-available/mymate"
# Pristine copy mymate-ssl reads to revert to plain HTTP (methods 1 & 2, and LXC reset) -
# deploy/build is excluded from the app tree, so the helper can't read it from there.
install -Dm644 "$FILES_DIR/nginx-mymate.conf"     "$STAGE/usr/share/mymate/nginx-mymate.conf"
install -Dm644 "$FILES_DIR/php-fpm-mymate.conf"   "$STAGE/etc/php/$PHP_VERSION/fpm/pool.d/mymate.conf"
install -Dm644 "$FILES_DIR/env.tmpl"              "$STAGE/usr/share/mymate/env.tmpl"
install -Dm755 "$FILES_DIR/firstboot.sh"          "$STAGE/usr/share/mymate/firstboot.sh"
install -Dm755 "$FILES_DIR/enable-icmp.sh"        "$STAGE/usr/share/mymate/enable-icmp.sh"
install -Dm644 "$FILES_DIR/sysctl-mymate.conf"    "$STAGE/etc/sysctl.d/99-mymate.conf"
for unit in "$FILES_DIR"/systemd/*.service; do
    install -Dm644 "$unit" "$STAGE/lib/systemd/system/$(basename "$unit")"
done

# TLS helper on PATH (the script + nginx template ship in the app tree at deploy/ssl/).
install -d "$STAGE/usr/local/sbin"
ln -sf "$INSTALL_PREFIX/deploy/ssl/mymate-ssl" "$STAGE/usr/local/sbin/mymate-ssl"

# --- 5. Debian control files ---------------------------------------------
say "Assembling DEBIAN control"
mkdir -p "$STAGE/DEBIAN"
INSTALLED_KB="$(du -sk "$STAGE" | cut -f1)"
sed -e "s/@VERSION@/$VERSION/g" \
    -e "s/@ARCH@/$ARCH/g" \
    -e "s/@INSTALLED_SIZE@/$INSTALLED_KB/g" \
    "$PKG_DIR/control" > "$STAGE/DEBIAN/control"
install -m755 "$PKG_DIR/postinst" "$STAGE/DEBIAN/postinst"
install -m755 "$PKG_DIR/prerm"    "$STAGE/DEBIAN/prerm"
install -m755 "$PKG_DIR/postrm"   "$STAGE/DEBIAN/postrm"
# conffiles: files apt should treat as editable config (never blindly overwrite on upgrade)
cat > "$STAGE/DEBIAN/conffiles" <<EOF
/etc/nginx/sites-available/mymate
/etc/php/$PHP_VERSION/fpm/pool.d/mymate.conf
/etc/sysctl.d/99-mymate.conf
EOF

# Ownership: the whole tree root:root; postinst re-owns the writable dirs to the
# runtime user. (dpkg-deb --root-owner-group forces this without needing fakeroot.)
say "Building the .deb"
DEB="$DIST/mymate_${VERSION}_${ARCH}.deb"
dpkg-deb --root-owner-group --build "$STAGE" "$DEB"

# --- 6. Ship the installer alongside the .deb -----------------------------
cp "$SCRIPT_DIR/install.sh" "$DIST/install.sh"
chmod +x "$DIST/install.sh"

say "Done."
echo
ls -lh "$DIST"/*.deb "$DIST"/install.sh 2>/dev/null
echo
echo "Self-contained package. On the target (Debian 13 / Ubuntu 22.04+):"
echo "    sudo apt install ./$(basename "$DEB")      # or: sudo ./install.sh"
