#!/usr/bin/env bash
#
# Bring the sales demo up to date after the shared code has been deployed.
#
# The demo runs the same checkout as production but has its own database (mymate_demo via
# .env.demo), so migrating prod does nothing for it. Forgetting that left the demo three
# migrations behind and 500ing (GitHub #48). This script is the demo deploy step, so run it
# every time you deploy backend changes:
#
#   deploy/demo/deploy.sh                 # migrate, re-seed, restart the demo daemons
#   deploy/demo/deploy.sh --no-seed       # skip the seed (it rewrites the 24h backfill)
#   deploy/demo/deploy.sh --migrate-only  # just the guarded migrate, no seed / restart
#
# --migrate-only is also what the mymate-demo-sim supervisor program runs before it starts
# the simulator, so a plain `supervisorctl restart` or a reboot picks up new migrations too.
#
# Everything here is idempotent: migrate is a no-op when nothing is pending and --seed is
# designed to be re-run.
#
# Env overrides: APP_DIR (default: the checkout this script lives in), PHP (default: php),
# SUPERVISORCTL (default: "sudo supervisorctl").
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
PHP="${PHP:-php}"
SUPERVISORCTL="${SUPERVISORCTL:-sudo supervisorctl}"

seed=1
restart=1
for arg in "$@"; do
    case "$arg" in
        --no-seed)      seed=0 ;;
        --no-restart)   restart=0 ;;
        --migrate-only) seed=0; restart=0 ;;
        -h|--help)      sed -n '2,21p' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) echo "demo-deploy: unknown option $arg" >&2; exit 2 ;;
    esac
done

die() { echo "demo-deploy: $*" >&2; exit 1; }

cd "$APP_DIR"

# Safety first. If .env.demo is missing Laravel silently falls back to .env, and if the config
# is cached APP_ENV is ignored altogether. Either way "migrate the demo" would really mean
# "migrate production", so check what database APP_ENV=demo actually resolves to.
[ -f .env.demo ] || die ".env.demo not found in $APP_DIR, refusing to run (it would fall back to .env)"
[ ! -f bootstrap/cache/config.php ] || die "config is cached (bootstrap/cache/config.php), APP_ENV=demo would be ignored. Run php artisan config:clear first"

db_for_env() { # $1 = APP_ENV value, or empty for the default (.env)
    local code='require "vendor/autoload.php"; $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $c = config("database.default"); echo $c, "/", config("database.connections.$c.database");'
    if [ -n "$1" ]; then
        APP_ENV="$1" "$PHP" -r "$code"
    else
        env -u APP_ENV "$PHP" -r "$code"
    fi
}

demo_db="$(db_for_env demo)" || die "could not boot the app with APP_ENV=demo"
prod_db="$(db_for_env "")"   || die "could not boot the app with the default env"
[ -n "${demo_db#*/}" ] || die "APP_ENV=demo resolved to an empty database name"
[ "$demo_db" != "$prod_db" ] || die "APP_ENV=demo resolves to the same database as production ($prod_db), refusing"

export APP_ENV=demo

echo "demo-deploy: migrating $demo_db"
"$PHP" artisan migrate --force --no-interaction

if [ "$seed" = 1 ]; then
    echo "demo-deploy: re-seeding the demo (viewer, topology, 24h backfill)"
    "$PHP" artisan mymate:demo --seed
fi

if [ "$restart" = 1 ]; then
    # The daemons keep running whatever code they started with, so they have to be bounced
    # to pick up the new build. The sim runs --migrate-only again on start, which is a no-op now.
    echo "demo-deploy: restarting the demo daemons"
    $SUPERVISORCTL restart mymate-demo-reverb mymate-demo-sim
fi

echo "demo-deploy: done"
