#!/bin/bash
#
# Verify a My Mate install is actually WORKING - run as root on the target (or in a
# systemd-enabled test container) AFTER installing the .deb. Checks that every service is
# active (not failed/looping), that nginx is really serving the app on :80 through
# php-fpm and the app, that the health probe is green, and that the bundled
# fping works. Exits non-zero if anything is wrong.
#
#   sudo deploy/build/tests/verify-install.sh
#
set -u

PHPV="${PHPV:-8.4}"
fail=0
pass() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✗ %s\033[0m\n' "$*"; fail=1; }

echo "== My Mate post-install verification =="

# Give freshly-(re)started units a moment to settle (Restart=always services can flap
# briefly on first boot while Redis/PG accept connections).
sleep 8

echo "-- systemd services active --"
for svc in postgresql redis-server "php${PHPV}-fpm" nginx \
           mymate-loop mymate-reverb mymate-horizon mymate-scheduler mymate-agent-hub; do
    if systemctl is-active --quiet "$svc"; then
        pass "$svc active"
    else
        bad "$svc is $(systemctl is-active "$svc" 2>/dev/null || echo missing)"
        systemctl --no-pager --lines=15 status "$svc" 2>&1 | sed 's/^/      /' | tail -12
    fi
done

echo "-- no failed units --"
# Ignore units that only ever fail inside a container (kernel modules, vconsole, etc.) and
# have nothing to do with My Mate - on a real VM/host these don't fail.
IGNORE='systemd-modules-load|systemd-firstboot|systemd-vconsole-setup|systemd-sysctl|systemd-networkd-wait|console-getty'
FAILED="$(systemctl list-units --state=failed --no-legend --plain 2>/dev/null | awk '{print $1}' | grep -vE "$IGNORE" || true)"
if [ -z "$FAILED" ]; then pass "no failed My Mate/service units"; else bad "failed units: $FAILED"; fi

# A fresh install is HTTPS by default (self-signed) - -k accepts the built-in cert. Fall
# back to http:// if the operator runs in a plain/proxy mode. CURL carries the right flags.
if curl -sk -o /dev/null https://localhost/ 2>/dev/null; then
    BASE="https://localhost"; CURL="curl -sk"
    echo "-- :80 redirects to HTTPS (self-signed by default) --"
    redir="$(curl -s -o /dev/null -w '%{http_code}' http://localhost/ 2>/dev/null || echo 000)"
    case "$redir" in 30[0-9]) pass "GET http:// -> $redir (redirect to HTTPS)";; *) bad "GET http:// -> $redir (expected a redirect to HTTPS)";; esac
else
    BASE="http://localhost"; CURL="curl -s"
fi

echo "-- nginx serves the app ($BASE -> php-fpm -> app) --"
code="$($CURL -o /dev/null -w '%{http_code}' "$BASE/" 2>/dev/null || echo 000)"
[ "$code" = "200" ] && pass "GET / -> 200 (SPA shell)" || bad "GET / -> $code"

# The shell must reference the built SPA bundle (proves assets shipped + are served).
if $CURL "$BASE/" 2>/dev/null | grep -q '/build/assets/'; then
    pass "SPA build assets referenced in the shell"
else
    bad "SPA build assets not found in the served HTML"
fi

echo "-- health probe (DB + Redis) via nginx --"
health="$($CURL -f "$BASE/api/health" 2>/dev/null || echo '{}')"
echo "     $health"
echo "$health" | grep -q '"status":"ok"'        && pass "status ok"         || bad "health status not ok"
echo "$health" | grep -q '"database":true'       && pass "database reachable" || bad "database not reachable"
echo "$health" | grep -q '"redis":true'          && pass "redis reachable"    || bad "redis not reachable"

echo "-- unauthenticated API is protected (401), health stays public --"
code="$($CURL -o /dev/null -w '%{http_code}' "$BASE/api/devices" 2>/dev/null || echo 000)"
[ "$code" = "401" ] && pass "GET /api/devices -> 401 (auth enforced)" || bad "GET /api/devices -> $code (expected 401)"

echo "-- the app boots as the service user --"
if runuser -u mymate -- php /opt/mymate/artisan --version 2>/dev/null | grep -qi 'Laravel'; then
    pass "artisan boots (Laravel framework loads)"
else
    bad "artisan failed to boot"
fi

echo "-- bundled fping 5.5 + ICMP as the non-root service user (the real up/down path) --"
/usr/local/sbin/fping -v 2>&1 | grep -q '5.5' && pass "fping 5.5" || bad "fping not 5.5"
# Runs as `mymate` (not root) - this is exactly how the engine probes, and proves the ICMP
# hard-requirement (ping sockets / CAP_NET_RAW) is actually satisfied for that user.
if runuser -u mymate -- /usr/local/sbin/fping --json -c1 -t300 127.0.0.1 2>/dev/null | grep -q '"summary"'; then
    pass "fping ICMP works as the mymate user"
else
    bad "fping ICMP FAILED as the mymate user - up/down monitoring would not work"
fi

echo
if [ "$fail" -eq 0 ]; then
    printf '\033[32m== ALL POST-INSTALL CHECKS PASSED ==\033[0m\n'
else
    printf '\033[31m== VERIFICATION FAILED ==\033[0m\n'
fi
exit $fail
