#!/bin/sh
# Guarded frontend build.
#
# WHY THIS EXISTS
# ---------------
# resources/js/lib/echo.ts reads import.meta.env.VITE_REVERB_APP_KEY. Vite only exposes
# VITE_-prefixed variables, but .env names the key REVERB_APP_KEY. So a bare `vite build`
# bakes `new Echo({ key: undefined })` into the bundle, Echo throws while the app is
# mounting, and the SPA renders a BLANK PAGE — every asset returns 200 and nothing in the
# console points at the cause. It is a genuinely nasty way to lose an install.
#
# deploy/build/build-deb.sh already sets the key, but `npm run build` (the obvious thing
# to run by hand) did not, and that is how we shipped a blank page for ~16 hours.
#
# This wrapper makes the safe path the default: it sources the key, refuses to build
# without one, and verifies the built bundle before declaring success.
set -e

cd "$(dirname "$0")/../.."

if [ -z "$VITE_REVERB_APP_KEY" ] && [ -f .env ]; then
    # \042 = double quote, \047 = single quote — octal keeps the sh quoting sane.
    VITE_REVERB_APP_KEY=$(grep -E '^REVERB_APP_KEY=' .env | head -1 | cut -d= -f2- | tr -d '\042\047 \r')
fi

if [ -z "$VITE_REVERB_APP_KEY" ]; then
    echo "ERROR: VITE_REVERB_APP_KEY is empty and REVERB_APP_KEY was not found in .env" >&2
    echo "Refusing to build: the result would be a blank SPA (Echo key undefined)." >&2
    echo "Set REVERB_APP_KEY in .env, or export VITE_REVERB_APP_KEY before building." >&2
    exit 1
fi
export VITE_REVERB_APP_KEY

echo "==> building with VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY"
npx vite build "$@"

# Post-build guard: 'key:void 0' is the minified signature of the blank-screen bug.
# Only meaningful for a default outDir; build-deb.sh passes --outDir and stages elsewhere.
if ls public/build/assets/*.js >/dev/null 2>&1; then
    if grep -ql 'key:void 0' public/build/assets/*.js 2>/dev/null; then
        echo >&2
        echo "ERROR: built bundle contains 'key:void 0' — this build WOULD render blank." >&2
        echo "The Reverb key did not reach vite." >&2
        exit 1
    fi
    echo "==> verified: Reverb key is baked into the bundle"
else
    echo "==> note: no bundle at public/build/assets — skipped the blank-screen check"
fi

# Installs run php-fpm/nginx as the service user while builds are often run as root over
# ssh; leaving public/build root-owned is a foot-gun. Defaults to the packaged user.
CHOWN_USER="${MYMATE_CHOWN_USER:-mymate}"
if [ "$(id -u)" = "0" ] && id "$CHOWN_USER" >/dev/null 2>&1; then
    chown -R "$CHOWN_USER:$CHOWN_USER" public/build 2>/dev/null || true
    echo "==> public/build owned by $CHOWN_USER"
fi
