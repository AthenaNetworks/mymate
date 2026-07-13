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
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -keyout "$CERT_DIR/tls.key" -out "$CERT_DIR/tls.crt" \
        -subj "/CN=$HOST" -addext "subjectAltName=DNS:$HOST,DNS:localhost,IP:127.0.0.1" >/dev/null 2>&1 \
        || echo "mymate: WARNING could not generate a TLS cert - HTTPS may not start"
    chmod 600 "$CERT_DIR/tls.key" 2>/dev/null || true
fi

# APP_KEY: use the one you passed in; otherwise generate an EPHEMERAL one (set APP_KEY
# yourself, or mount .env, to keep sessions/encrypted data across restarts).
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=base64:.+' .env; then
    php artisan key:generate --force >/dev/null 2>&1 || true
    echo "mymate: generated an ephemeral APP_KEY - set APP_KEY to persist across restarts"
fi

# Wait for Postgres before migrating.
DB_HOST="${DB_HOST:-postgres}"; DB_PORT="${DB_PORT:-5432}"; DB_USERNAME="${DB_USERNAME:-mymate}"
echo "mymate: waiting for postgres at ${DB_HOST}:${DB_PORT} ..."
i=0
until pg_isready -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then echo "mymate: postgres still not ready after 2m, carrying on"; break; fi
    sleep 2
done

php artisan migrate --force || echo "mymate: migrate failed - will retry next boot"
php artisan config:clear >/dev/null 2>&1 || true
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
