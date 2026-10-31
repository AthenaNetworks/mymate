#!/bin/sh
# Prep the app on container start, then hand off to supervisor (the CMD).
set -e
cd /app

# This app requires Postgres + Redis. The shipped .env.example defaults to sqlite/database
# (fine for the framework, wrong here), so force sane defaults unless the operator overrode
# them - a bare `docker run` with DB_HOST + REDIS_HOST then just works.
export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
export SESSION_DRIVER="${SESSION_DRIVER:-redis}"    # no sessions table exists; sessions live in redis
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-redis}"
export CACHE_STORE="${CACHE_STORE:-redis}"
export BROADCAST_CONNECTION="${BROADCAST_CONNECTION:-reverb}"

# A .env so artisan is happy; real container env vars take precedence over it in Laravel,
# so this only supplies defaults for anything you didn't set.
[ -f .env ] || cp .env.example .env

# Self-signed cert for the HTTPS listener, unless you've mounted your own at /etc/mymate/tls.
# Browsers warn once on a self-signed cert; for a trusted one, mount tls.crt/tls.key there or
# terminate TLS at a proxy in front and expose the HTTP port instead.
CERT_DIR=/etc/mymate/tls
if [ ! -s "$CERT_DIR/tls.crt" ] || [ ! -s "$CERT_DIR/tls.key" ]; then
    mkdir -p "$CERT_DIR"
    HOST=$(printf '%s' "${APP_URL:-}" | sed -E 's#^https?://##; s#[:/].*$##')
    [ -z "$HOST" ] && HOST=localhost
    echo "mymate: generating a self-signed TLS certificate for $HOST"
    # An IP address has to go in as an IP SAN: listed as a DNS name it never matches, and
    # browsers and agents reject the certificate.
    case "$HOST" in
        *[!0-9.]*) SAN="DNS:$HOST,DNS:localhost,IP:127.0.0.1" ;;
        *) SAN="IP:$HOST,DNS:localhost,IP:127.0.0.1" ;;
    esac
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout "$CERT_DIR/tls.key" -out "$CERT_DIR/tls.crt" \
        -subj "/CN=$HOST" -addext "subjectAltName=$SAN" >/dev/null 2>&1 \
        || echo "mymate: WARNING could not generate a TLS cert - HTTPS may not start"
    chmod 600 "$CERT_DIR/tls.key" 2>/dev/null || true
fi

# Secrets: use the ones you passed in. Otherwise generate them ONCE and keep them in the state
# volume (/var/lib/mymate), so they survive restarts and image upgrades. APP_KEY matters most:
# device credentials are stored encrypted with it, so a fresh key on every start used to leave
# every stored password and community string unreadable after a restart.
STATE_DIR=/var/lib/mymate
mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"
# persisted_secret NAME FILE GENERATOR: export NAME from the env, else from FILE, else generate
# it into FILE. An explicit value always wins (and isn't written anywhere).
persisted_secret() {
    eval "current=\${$1:-}"
    if [ -n "$current" ] && [ "$current" != "change-me" ]; then
        return
    fi
    if [ ! -s "$2" ]; then
        ( umask 077; $3 > "$2.tmp" ) && [ -s "$2.tmp" ] && mv "$2.tmp" "$2"
        echo "mymate: generated $1 and saved it in $2 - keep the mymate-state volume (or set $1) to keep it"
    fi
    export "$1=$(cat "$2")"
}
# A key in a mounted .env counts as passed in (exporting a generated one would override it).
grep -qE '^APP_KEY=base64:.+' .env || persisted_secret APP_KEY "$STATE_DIR/app_key" "php artisan key:generate --show --no-ansi"
persisted_secret REVERB_APP_SECRET "$STATE_DIR/reverb_app_secret" "openssl rand -hex 32"

# `docker compose exec app php artisan ...` (creating the first admin, say) doesn't run through this
# script, so it never sees the exports above: no APP_KEY, and .env.example's sqlite database. Write
# them into .env as well, which every artisan run reads. A real environment variable still wins.
set_dotenv() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}
for k in DB_CONNECTION SESSION_DRIVER QUEUE_CONNECTION CACHE_STORE BROADCAST_CONNECTION APP_KEY REVERB_APP_SECRET; do
    eval "v=\${$k:-}"
    [ -n "$v" ] && set_dotenv "$k" "$v"
done

# Wait for Postgres before migrating.
DB_HOST="${DB_HOST:-postgres}"; DB_PORT="${DB_PORT:-5432}"; DB_USERNAME="${DB_USERNAME:-mymate}"
echo "mymate: waiting for postgres at ${DB_HOST}:${DB_PORT} ..."
i=0
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then echo "mymate: postgres still not ready after 2m, carrying on"; break; fi
    sleep 2
done

# Migrations run here, before supervisord starts php-fpm/horizon/loop below (via `exec "$@"`),
# so on `docker compose pull && up -d` the new code always meets the new schema.
php artisan migrate --force || echo "mymate: migrate failed - will retry next boot"
# Drop stale compiled config/views/events - the storage volume persists these across image
# versions, so an upgrade could otherwise render a Blade view compiled against the old build.
php artisan config:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true
php artisan event:clear >/dev/null 2>&1 || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Backup engine (Rusted): generate a config with its own token on first start, init the
# db + git backup repo, and point My Mate at it so backups just work. Mount /etc/rusted and
# /var/lib/rusted as volumes to keep the token + captured configs across container recreation
# (otherwise a fresh token is generated each start - harmless, My Mate is re-pointed below).
if [ -x /usr/local/bin/rusted ]; then
    if [ ! -f /etc/rusted/config.toml ]; then
        echo "mymate: initialising the Rusted backup engine"
        rusted config init --global --data-dir /var/lib/rusted >/dev/null 2>&1 || true
        # Rusted defaults to :8080 (taken by Reverb here) - bind loopback:8410 instead.
        sed -i 's|^api_addr[[:space:]]*=.*|api_addr  = "127.0.0.1:8410"|' /etc/rusted/config.toml 2>/dev/null || true
        rusted --config /etc/rusted/config.toml init >/dev/null 2>&1 || true
        chown -R www-data:www-data /etc/rusted /var/lib/rusted 2>/dev/null || true
    fi
    # Always re-point My Mate at the current token so the two never drift apart.
    php artisan mymate:backup:configure --url http://127.0.0.1:8410 --from-rusted /etc/rusted/config.toml >/dev/null 2>&1 \
        || echo "mymate: backup engine not auto-configured - set it in Settings"
fi

echo "mymate: starting services"
exec "$@"
