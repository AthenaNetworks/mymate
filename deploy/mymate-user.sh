#!/usr/bin/env bash
#
# Create the `mymate` service user and give it the parts of a checkout it has to write, for a
# from-source install. The Debian package and the LXC template do the same in their postinst,
# so this is only for running My Mate from a git checkout. Idempotent - safe to re-run.
#
#   sudo deploy/mymate-user.sh                # create the user, fix ownership
#   sudo deploy/mymate-user.sh alice bob      # ... and let these login users share the logs
#
# Every My Mate process (php-fpm, Horizon, Reverb, the loop, the scheduler, the agent hub) runs
# as mymate. It owns storage/, bootstrap/cache and .env and nothing else: the code stays owned
# by whoever deploys it, and nobody but the owner can write it. Users named on the command line
# join the mymate group, so they can run artisan and read the logs without sudo (from their
# next login). The Rusted and CMR sidecars keep their own users (see their provision.sh).
#
# Env knobs: APP_DIR (default: the checkout this script lives in), LOG_DIR (default
# /var/log/mymate, where the shipped supervisor config writes).
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
LOG_DIR="${LOG_DIR:-/var/log/mymate}"
RUN_USER=mymate
RUN_GROUP=mymate

say() { printf '\033[36m==>\033[0m %s\n' "$*"; }
[ "$(id -u)" -eq 0 ] || { echo "error: run as root (sudo $0)" >&2; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "error: $APP_DIR is not a My Mate checkout" >&2; exit 1; }

# --- 1. user + group ---------------------------------------------------------
if ! getent group "$RUN_GROUP" >/dev/null; then
    say "Creating the $RUN_GROUP group"
    groupadd --system "$RUN_GROUP"
fi
if ! getent passwd "$RUN_USER" >/dev/null; then
    say "Creating the $RUN_USER system user"
    useradd --system --gid "$RUN_GROUP" --home-dir "$APP_DIR" --no-create-home \
        --shell /usr/sbin/nologin --comment "My Mate" "$RUN_USER"
fi
for member in "$@"; do
    getent passwd "$member" >/dev/null || { echo "warning: no user $member, skipping" >&2; continue; }
    say "Adding $member to the $RUN_GROUP group"
    usermod -aG "$RUN_GROUP" "$member"
done

# --- 2. what mymate writes -----------------------------------------------------
# Group-writable with setgid directories, so files made by mymate or by a group member stay
# in the group and both can keep writing them (log files are created 0664, config/logging.php).
say "Handing storage/ and bootstrap/cache to $RUN_USER"
for d in "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"; do
    chown -R "$RUN_USER:$RUN_GROUP" "$d"
    chmod -R ug+rwX,o-w "$d"
    find "$d" -type d -exec chmod g+s {} +
done
for f in "$APP_DIR"/.env "$APP_DIR"/.env.*; do
    [ -f "$f" ] || continue
    case "$f" in *.example) continue ;; esac
    # Secrets (APP_KEY, the DB password): readable by mymate and its group, nobody else.
    chown "$RUN_USER:$RUN_GROUP" "$f"
    chmod 640 "$f"
done
mkdir -p "$LOG_DIR"
chown "$RUN_USER:$RUN_GROUP" "$LOG_DIR"

# --- 3. the code itself ----------------------------------------------------------
# No world-writable code: the services would run whatever another local user dropped in.
say "Removing world write access from the code"
find "$APP_DIR" -perm -o+w ! -type l -exec chmod o-w {} +

say "Done. Run the services as $RUN_USER: deploy/supervisor/mymate.conf, and user = $RUN_USER in the php-fpm pool."
