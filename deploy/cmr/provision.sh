#!/usr/bin/env bash
#
# Provision the MikroTik CMR controller (cmr-ctrl) for My Mate and wire My Mate up to it, so
# devices pointed at this host pair and show up in the discovery review queue. Idempotent - safe
# to re-run. Needs root (or sudo).
#
# The cmr-ctrl binary is resolved in this order:
#   1. $CMR_BIN               - a prebuilt binary to install (what the packages ship)
#   2. an already-installed /usr/local/bin/cmr-ctrl
#   3. download the pinned release (deploy/cmr/fetch-cmr.sh, checksum-verified)
#
# The admin token and the controller identity key are generated once, here, and never leave
# the box. Devices pin the identity's fingerprint (Settings -> CMR controller shows it), so a
# re-run keeps both.
#
# Env knobs: CMR_ADMIN_PORT (default 8411), APP_DIR (default /opt/mymate),
#            APP_USER (user to run artisan as; default: the owner of APP_DIR).
set -euo pipefail

ADMIN_PORT="${CMR_ADMIN_PORT:-8411}"
ADMIN_ADDR="127.0.0.1:${ADMIN_PORT}"
APP_DIR="${APP_DIR:-/opt/mymate}"
BIN=/usr/local/bin/cmr-ctrl
ETC=/etc/mymate-cmr
TOKEN_FILE="$ETC/admin.token"
DATA=/var/lib/mymate-cmr
UNIT=/etc/systemd/system/mymate-cmr.service

SUDO=""; [ "$(id -u)" -eq 0 ] || SUDO=sudo
say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

# Same privilege drop as deploy/rusted/provision.sh: runuser when root, sudo -u otherwise.
run_as_app() {
    if [ -n "$SUDO" ]; then
        $SUDO -u "$APP_USER" "$@"
        return
    fi
    local ru
    ru="$(command -v runuser || true)"
    [ -n "$ru" ] || { for c in /usr/sbin/runuser /sbin/runuser; do [ -x "$c" ] && ru="$c" && break; done; }
    if [ -n "$ru" ]; then
        "$ru" -u "$APP_USER" -- "$@"
    else
        su -s /bin/sh "$APP_USER" -c "$(printf '%q ' "$@")"
    fi
}

# --- 1. binary ---------------------------------------------------------------
if [ -n "${CMR_BIN:-}" ] && [ -f "${CMR_BIN:-}" ]; then
    say "Installing bundled cmr-ctrl binary"
    $SUDO install -Dm0755 "$CMR_BIN" "$BIN"
elif [ -x "$BIN" ]; then
    say "cmr-ctrl already installed at $BIN"
else
    case "$(uname -m)" in
        x86_64) arch=amd64 ;;
        aarch64|arm64) arch=arm64 ;;
        *) echo "error: no cmr-ctrl build for $(uname -m)" >&2; exit 1 ;;
    esac
    say "Downloading the pinned cmr-ctrl release ($arch)"
    tmp="$(mktemp)"
    ARCH="$arch" OUT="$tmp" bash "$APP_DIR/deploy/cmr/fetch-cmr.sh"
    $SUDO install -Dm0755 "$tmp" "$BIN"
    rm -f "$tmp"
fi

# --- 2. dedicated user + dirs ------------------------------------------------
if ! id mymate-cmr >/dev/null 2>&1; then
    say "Creating the mymate-cmr system user"
    $SUDO useradd --system --home "$DATA" --shell /usr/sbin/nologin mymate-cmr
fi
$SUDO mkdir -p "$DATA" "$ETC"
$SUDO chown mymate-cmr:mymate-cmr "$DATA"
$SUDO chmod 0750 "$DATA"

# --- 3. admin token (generated once, then left alone) -------------------------
if ! $SUDO test -s "$TOKEN_FILE"; then
    say "Generating the admin API token"
    $SUDO sh -c "umask 077; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > '$TOKEN_FILE'"
fi
$SUDO chown root:mymate-cmr "$ETC" "$TOKEN_FILE"
$SUDO chmod 0750 "$ETC"
$SUDO chmod 0640 "$TOKEN_FILE"

# --- 4. service --------------------------------------------------------------
if command -v systemctl >/dev/null 2>&1; then
    say "Installing + starting the mymate-cmr service"
    $SUDO install -m0644 "$APP_DIR/deploy/cmr/mymate-cmr.service" "$UNIT"
    $SUDO systemctl daemon-reload
    $SUDO systemctl enable mymate-cmr
    # restart, not `enable --now`, so a package upgrade picks up the new binary straight away.
    $SUDO systemctl restart mymate-cmr
else
    say "No systemd here - start it yourself (see $APP_DIR/deploy/cmr/mymate-cmr.service)"
fi

# --- 5. health check ---------------------------------------------------------
TOKEN="$($SUDO cat "$TOKEN_FILE")"
for i in $(seq 1 10); do
    if curl -fsS -H "Authorization: Bearer $TOKEN" "http://${ADMIN_ADDR}/healthz" >/dev/null 2>&1; then
        say "cmr-ctrl is healthy on http://${ADMIN_ADDR}"
        break
    fi
    sleep 1
    [ "$i" -eq 10 ] && { echo "error: cmr-ctrl did not answer /healthz on ${ADMIN_ADDR}" >&2; exit 1; }
done

# --- 6. wire My Mate up to it (token saved encrypted; the app user never reads $ETC) ---
if [ -f "$APP_DIR/artisan" ]; then
    APP_USER="${APP_USER:-$(stat -c '%U' "$APP_DIR")}"
    say "Configuring My Mate to use it (as ${APP_USER})"
    run_as_app php "$APP_DIR/artisan" mymate:cmr:configure --url "http://${ADMIN_ADDR}" --token "$TOKEN"
fi

say "Done. Point CMR devices at this host on tcp/54321 - Settings -> CMR controller has the fingerprint."
say "Make sure tcp/54321 is reachable from your devices (firewall)."
