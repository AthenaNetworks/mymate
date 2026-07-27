#!/bin/sh
# My Mate - first-boot provisioning. Runs ONCE on the first boot of a prebuilt image
# (LXC template / VM image) where the package was installed at build time on a different
# host. It gives this instance its own identity - fresh APP_KEY, Reverb secret and DB
# password (a cloned template must never share secrets) - and points auth at the machine's
# current hostname/IP. Guarded by a marker so it never runs twice; a plain `.deb` install
# creates that marker in its postinst, so this is a no-op there.
set -e

APP=/opt/mymate
ENVF="$APP/.env"
MARKER="$APP/storage/.provisioned"

[ -f "$MARKER" ] && exit 0
[ -f "$ENVF" ] || exit 0

as_app() { su -s /bin/sh mymate -c "cd $APP && $1"; }
set_env() {
    if grep -qE "^$1=" "$ENVF"; then sed -i "s|^$1=.*|$1=$2|" "$ENVF"; else printf '%s=%s\n' "$1" "$2" >> "$ENVF"; fi
}

echo "my-mate: first-boot provisioning..."

# 1. Per-instance secrets (never ship a shared APP_KEY / DB password / Reverb secret).
as_app "php artisan key:generate --force --no-interaction" || true
set_env REVERB_APP_SECRET "$(openssl rand -hex 24)"
NEWPW="$(openssl rand -hex 24)"
if su postgres -c "psql -c \"ALTER ROLE mymate LOGIN PASSWORD '$NEWPW'\"" >/dev/null 2>&1; then
    set_env DB_PASSWORD "$NEWPW"
fi

# 1b. Redis is a transient broker only (queues, cache, broadcasting) - disable persistence so it
# can't fill the disk with its RDB dump or spike memory (redis-check-rdb), and never block writes
# on a failed save (GitHub #16). Also cap its memory (40% of RAM, floor 256MB) so a runaway can't
# OOM-kill redis-server and take monitoring down - nothing here is durable, so allkeys-lru eviction
# under pressure is safe. Best-effort; runs here too since the template may have been built with
# redis not running, so the postinst couldn't persist it.
if command -v redis-cli >/dev/null 2>&1; then
    redis-cli CONFIG SET save "" >/dev/null 2>&1 || true
    redis-cli CONFIG SET appendonly no >/dev/null 2>&1 || true
    redis-cli CONFIG SET stop-writes-on-bgsave-error no >/dev/null 2>&1 || true
    mm_kb=$(awk '/^MemTotal:/{print int($2*40/100)}' /proc/meminfo 2>/dev/null || echo "")
    if [ -n "$mm_kb" ] && [ "$mm_kb" -gt 262144 ]; then
        redis-cli CONFIG SET maxmemory "${mm_kb}kb" >/dev/null 2>&1 || true
    else
        redis-cli CONFIG SET maxmemory 256mb >/dev/null 2>&1 || true
    fi
    redis-cli CONFIG SET maxmemory-policy allkeys-lru >/dev/null 2>&1 || true
    redis-cli CONFIG REWRITE >/dev/null 2>&1 || true
fi

# 2. Point SPA cookie auth at this machine's identity. Prefer the source IP of the
# default route (the address clients actually reach this box on) over the first entry
# from `hostname -I`, which can be a secondary/wrong interface. Login no longer depends
# on getting this exactly right - the app also trusts the same-origin request host at
# runtime - but a good APP_URL keeps absolute links and WebSocket URLs correct.
HOST_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -n1)"
[ -n "$HOST_IP" ] || HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
STATEFUL="localhost,127.0.0.1,::1"
[ -n "$HOST_FQDN" ] && STATEFUL="$HOST_FQDN,$STATEFUL"
[ -n "$HOST_IP" ]   && STATEFUL="$HOST_IP,$STATEFUL"
set_env SANCTUM_STATEFUL_DOMAINS "$STATEFUL"
set_env APP_URL "http://${HOST_IP:-localhost}"
set_env CORS_ALLOWED_ORIGINS "*"

# 2b. HTTPS by default - a per-instance self-signed cert so this clone is encrypted on
# first boot (browser trust warning until the operator runs `mymate-ssl setup`). The cert
# is stripped from the template by the LXC build so every instance gets its own.
# NO_RESTART: this unit is ordered Before the engine services (they start fresh right after
# with the new config); restarting them from here would deadlock.
SSL_HOST="$HOST_IP"
case "$HOST_FQDN" in *.*) [ "$HOST_FQDN" != localhost ] && SSL_HOST="$HOST_FQDN" ;; esac
MYMATE_SSL_NO_RESTART=1 mymate-ssl self-signed "${SSL_HOST:-}" >/dev/null 2>&1 \
    || echo "my-mate: WARNING self-signed HTTPS setup failed - serving plain HTTP." >&2

# 3. Re-assert ICMP for THIS environment - the build-time sysctl/capability may not carry
# across a template import (different network + user namespace). Prefer unprivileged ping
# sockets, fall back to CAP_NET_RAW (same logic the .deb postinst hard-requires).
/usr/share/mymate/enable-icmp.sh \
    || echo "my-mate: WARNING ICMP could not be enabled - up/down monitoring will not work until the host allows it." >&2

# 4. Make sure the schema is current (template may predate a package upgrade).
as_app "php artisan migrate --force --no-interaction" || true
as_app "php artisan config:clear" || true

# 4b. Re-provision the backup engine so this instance gets its OWN rusted token + backup
# repo (the template's were stripped by the LXC build). Only touches rusted-api, not the
# engine services this unit is ordered Before=, so no deadlock. Best-effort.
if [ -x /usr/local/bin/rusted ] && [ -f "$APP/deploy/rusted/provision.sh" ]; then
    APP_DIR="$APP" APP_USER=mymate bash "$APP/deploy/rusted/provision.sh" \
        || echo "my-mate: WARNING backup engine setup failed - configure it later in Settings." >&2
fi

touch "$MARKER"

# php-fpm may have started before us; it re-reads .env per request, but restart it for a
# clean slate. NOTE: do NOT restart mymate-loop/reverb/horizon/scheduler here - this unit
# is ordered Before= them, so systemd starts them right after we exit (restarting them from
# inside would deadlock: they'd wait on us, we'd wait on them).
systemctl restart php8.4-fpm 2>/dev/null || true

echo "my-mate: first-boot provisioning complete."
