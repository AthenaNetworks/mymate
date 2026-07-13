#!/usr/bin/env bash
#
# Provision the Rusted config-backup engine for My Mate and wire My Mate up to it, so
# device backups work out of the box. Idempotent - safe to re-run. Needs root (or sudo).
#
# The rusted binary is resolved in this order:
#   1. an already-installed /usr/local/bin/rusted
#   2. $RUSTED_BIN            - a prebuilt binary to install (what the packages ship)
#   3. build from $RUSTED_SRC - a rusted source checkout (needs Go 1.26+)
#
# After provisioning it reads the generated API token back out of the config and saves it
# into My Mate (encrypted), so no manual Settings step is needed.
#
# Env knobs: RUSTED_PORT (default 8410), APP_DIR (default /opt/mymate),
#            APP_USER (user to run artisan as; default: the owner of APP_DIR).
set -euo pipefail

RUSTED_PORT="${RUSTED_PORT:-8410}"
RUSTED_ADDR="127.0.0.1:${RUSTED_PORT}"
APP_DIR="${APP_DIR:-/opt/mymate}"
BIN=/usr/local/bin/rusted
CONFIG=/etc/rusted/config.toml
DATA=/var/lib/rusted

SUDO=""; [ "$(id -u)" -eq 0 ] || SUDO=sudo
say() { printf '\033[36m==>\033[0m %s\n' "$*"; }

# --- 1. binary ---------------------------------------------------------------
if [ -x "$BIN" ]; then
    say "rusted already installed at $BIN"
elif [ -n "${RUSTED_BIN:-}" ] && [ -f "${RUSTED_BIN:-}" ]; then
    say "Installing bundled rusted binary"
    $SUDO install -Dm0755 "$RUSTED_BIN" "$BIN"
elif [ -n "${RUSTED_SRC:-}" ] && [ -d "${RUSTED_SRC:-}" ]; then
    say "Building rusted from source ($RUSTED_SRC)"
    command -v go >/dev/null 2>&1 || { echo "error: Go toolchain needed to build rusted" >&2; exit 1; }
    tmp="$(mktemp)"
    ( cd "$RUSTED_SRC" && CGO_ENABLED=0 GOTOOLCHAIN=auto go build -o "$tmp" ./cmd/rusted )
    $SUDO install -Dm0755 "$tmp" "$BIN"
    rm -f "$tmp"
else
    echo "error: no rusted binary. Set RUSTED_BIN=<prebuilt> or RUSTED_SRC=<checkout>." >&2
    exit 1
fi

# --- 2. dedicated user + data dirs ------------------------------------------
if ! id rusted >/dev/null 2>&1; then
    say "Creating the rusted system user"
    $SUDO useradd --system --home "$DATA" --shell /usr/sbin/nologin rusted
fi
$SUDO mkdir -p "$DATA/backups" /etc/rusted

# --- 3. config (generated once, then left alone) ----------------------------
if [ ! -f "$CONFIG" ]; then
    say "Generating config with a random API token + secret"
    $SUDO "$BIN" config init --global --data-dir "$DATA"
    # Rusted defaults to :8080 which clashes with Reverb - bind loopback:8410 instead.
    $SUDO sed -i "s|^api_addr\s*=.*|api_addr  = \"${RUSTED_ADDR}\"|" "$CONFIG"
else
    say "Config already at $CONFIG (leaving it untouched)"
fi

# --- 4. init db + backup git repo, then hand ownership to the rusted user ----
say "Initialising database + backup git repository"
$SUDO "$BIN" --config "$CONFIG" init || true
$SUDO chown -R rusted:rusted "$DATA"
$SUDO chown root:rusted "$CONFIG"
$SUDO chmod 640 "$CONFIG"

# --- 5. service --------------------------------------------------------------
if command -v systemctl >/dev/null 2>&1; then
    say "Installing + starting the rusted-api service"
    $SUDO install -m0644 "$APP_DIR/deploy/rusted/rusted-api.service" /etc/systemd/system/rusted-api.service
    $SUDO systemctl daemon-reload
    $SUDO systemctl enable --now rusted-api
else
    say "No systemd here - start rusted yourself: $BIN --config $CONFIG serve"
fi

# --- 6. health check ---------------------------------------------------------
for i in $(seq 1 10); do
    if curl -fsS "http://${RUSTED_ADDR}/healthz" >/dev/null 2>&1; then
        say "Rusted is healthy on http://${RUSTED_ADDR}"
        break
    fi
    sleep 1
    [ "$i" -eq 10 ] && { echo "error: rusted did not answer /healthz on ${RUSTED_ADDR}" >&2; exit 1; }
done

# --- 7. wire My Mate up to it (saves the token into My Mate, encrypted) ------
# Read the token as root (the config is root:rusted 640, so the app user can't) and
# hand it to artisan run as the app user - the app user never needs to read /etc/rusted.
if [ -f "$APP_DIR/artisan" ]; then
    APP_USER="${APP_USER:-$(stat -c '%U' "$APP_DIR")}"
    TOKEN="$($SUDO grep -oP '^\s*api_token\s*=\s*"\K[^"]+' "$CONFIG" || true)"
    if [ -n "$TOKEN" ]; then
        say "Configuring My Mate to use it (as ${APP_USER})"
        $SUDO -u "$APP_USER" php "$APP_DIR/artisan" mymate:backup:configure \
            --url "http://${RUSTED_ADDR}" --token "$TOKEN"
    else
        echo "warning: couldn't read api_token from $CONFIG - set it in Settings manually." >&2
    fi
fi

say "Done. Device backups are ready - enable them per device in the inspector."
