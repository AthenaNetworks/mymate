#!/usr/bin/env bash
#
# My Mate remote agent installer (Linux systemd / macOS launchd). Installs the static
# binary, writes the config, and registers a service that starts at boot and restarts on
# failure. Run from an unpacked release archive (the binary + service file sit next to it).
#
#   sudo ./install.sh --url https://mymate.example.com --token mma_xxxxx
#   # or set MYMATE_URL / MYMATE_AGENT_TOKEN in the environment
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN="/usr/local/bin/mymate-agent"

URL="${MYMATE_URL:-}"
TOKEN="${MYMATE_AGENT_TOKEN:-}"
while [ $# -gt 0 ]; do
    case "$1" in
        --url)   URL="$2"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Please run as root: sudo ./install.sh ..." >&2; exit 1; }
[ -f "$HERE/mymate-agent" ] || { echo "mymate-agent binary not found next to this script." >&2; exit 1; }
[ -n "$URL" ]   || { echo "Missing --url (or MYMATE_URL)." >&2; exit 1; }
[ -n "$TOKEN" ] || { echo "Missing --token (or MYMATE_AGENT_TOKEN)." >&2; exit 1; }

echo "==> Installing binary -> $BIN"
install -m755 "$HERE/mymate-agent" "$BIN"

case "$(uname -s)" in
Linux)
    echo "==> Writing config -> /etc/mymate-agent/agent.env"
    mkdir -p /etc/mymate-agent
    umask 077
    cat > /etc/mymate-agent/agent.env <<EOF
MYMATE_URL=$URL
MYMATE_AGENT_TOKEN=$TOKEN
EOF
    echo "==> Installing systemd service"
    install -m644 "$HERE/mymate-agent.service" /etc/systemd/system/mymate-agent.service
    systemctl daemon-reload
    systemctl enable --now mymate-agent
    echo "==> Done. Status:"
    systemctl --no-pager --lines=5 status mymate-agent || true
    echo "    Logs: journalctl -u mymate-agent -f"
    ;;
Darwin)
    PLIST=/Library/LaunchDaemons/com.athenanetworks.mymate-agent.plist
    echo "==> Installing launchd daemon -> $PLIST"
    sed -e "s|__MYMATE_URL__|$URL|" -e "s|__MYMATE_AGENT_TOKEN__|$TOKEN|" \
        "$HERE/com.athenanetworks.mymate-agent.plist" > "$PLIST"
    chown root:wheel "$PLIST"; chmod 644 "$PLIST"
    launchctl unload "$PLIST" 2>/dev/null || true
    launchctl load "$PLIST"
    echo "==> Done. Logs: tail -f /var/log/mymate-agent.log"
    ;;
*)
    echo "Unsupported OS $(uname -s). The binary is at $BIN - run it with MYMATE_URL + MYMATE_AGENT_TOKEN set." >&2
    exit 1
    ;;
esac
