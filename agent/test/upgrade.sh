#!/usr/bin/env bash
#
# Remote agent upgrade, end to end, with the real agent and the real hub:
#   1. an agent on v0.0.1 is upgraded to v0.0.2 - downloads it from the hub, verifies it, starts it
#      under its supervisor, and the new version reports itself healthy;
#   2. it's then sent a "v0.0.3" that passes the version check but crashes on start - the
#      supervisor must roll it back to v0.0.2 by itself, bring the agent back online, and report it;
#   3. then a "v0.0.4" that starts but hangs (never connects, ignores SIGTERM) - it can't give up by
#      itself, so the supervisor has to stop it at the deadline and roll back the same way.
#
# Run from the repo root like integration.sh (composer deps, .env, migrated DB, Redis, Go on PATH).
# It runs the hub against its own storage folder, so it never touches a real agent-release cache.
#   agent/test/upgrade.sh
set -u
cd "$(dirname "${BASH_SOURCE[0]}")/../.." # repo root
PORT="${HUB_PORT:-9402}"
WORK=$(mktemp -d)
export LARAVEL_STORAGE_PATH="$WORK/storage"
mkdir -p "$LARAVEL_STORAGE_PATH"/{logs,app,framework/{cache/data,sessions,views}}
HUB=""; AGENT=""; AID=""

q() { php artisan tinker --execute="$1" 2>/dev/null | tail -1; }
say() { printf '\033[36m>>>\033[0m %s\n' "$*"; }
fail() {
    printf '\033[31mFAIL:\033[0m %s\n' "$*"
    echo "--- agent log ---"; tail -30 "$WORK/agent.log" 2>/dev/null
    echo "--- hub log ---"; tail -20 "$WORK/hub.log" 2>/dev/null
    cleanup; exit 1
}
cleanup() {
    [ -n "$AGENT" ] && kill "$AGENT" 2>/dev/null
    [ -n "$HUB" ] && kill "$HUB" 2>/dev/null
    [ -n "$AID" ] && q "\App\Models\Agent::destroy($AID);" >/dev/null 2>&1
    rm -rf "$WORK"
}
trap cleanup EXIT
wait_for() { local secs="$1"; shift; for _ in $(seq 1 "$secs"); do eval "$@" && return 0; sleep 1; done; return 1; }
field() { q "echo \App\Models\Agent::find($AID)->$1;"; }
status() { q "echo \App\Models\Agent::find($AID)->status->value;"; }

# --- builds ------------------------------------------------------------------
say "building v0.0.1 (installed) and v0.0.2 (the upgrade)"
mkdir -p "$WORK/base"
( cd agent && CGO_ENABLED=0 go build -ldflags "-X github.com/AthenaNetworks/mymate/agent/internal/agent.Version=v0.0.1" -o "$WORK/base/mymate-agent" . ) || fail "build v0.0.1"
rel() { echo "$LARAVEL_STORAGE_PATH/app/agent-releases/$1/linux_amd64"; }
mkdir -p "$(rel v0.0.2)" "$(rel v0.0.3)" "$(rel v0.0.4)"
( cd agent && CGO_ENABLED=0 go build -ldflags "-X github.com/AthenaNetworks/mymate/agent/internal/agent.Version=v0.0.2" -o "$(rel v0.0.2)/mymate-agent" . ) || fail "build v0.0.2"
# A bad release: says the right version, then dies as soon as it's started for real.
printf '#!/bin/sh\nif [ "$1" = version ]; then echo "mymate-agent v0.0.3 (linux/amd64)"; exit 0; fi\necho "boom" >&2; exit 1\n' > "$(rel v0.0.3)/mymate-agent"
chmod +x "$(rel v0.0.3)/mymate-agent"
# Another: starts and then just hangs, deaf to SIGTERM.
printf '#!/bin/sh\nif [ "$1" = version ]; then echo "mymate-agent v0.0.4 (linux/amd64)"; exit 0; fi\ntrap "" TERM\nwhile :; do sleep 1; done\n' > "$(rel v0.0.4)/mymate-agent"
chmod +x "$(rel v0.0.4)/mymate-agent"

TOKEN=$(php artisan mymate:agent:create "ci-upgrade" 2>&1 | grep -oE 'mma_[A-Za-z0-9]+' | head -1)
[ -n "$TOKEN" ] || fail "could not enrol an agent"
AID=$(q 'echo \App\Models\Agent::where("name","ci-upgrade")->value("id");')

php artisan mymate:agent-hub --port="$PORT" >"$WORK/hub.log" 2>&1 & HUB=$!
sleep 2
env MYMATE_URL="http://127.0.0.1:$PORT" MYMATE_AGENT_TOKEN="$TOKEN" MYMATE_AGENT_LOG=debug \
    MYMATE_AGENT_STATE_DIR="$WORK/state" MYMATE_AGENT_HEALTH_DEADLINE=10s MYMATE_AGENT_HEALTHY_AFTER=3s "$WORK/base/mymate-agent" >"$WORK/agent.log" 2>&1 & AGENT=$!

wait_for 20 '[ "$(field version)" = v0.0.1 ]' || fail "agent never connected"
[ "$(q "echo \App\Models\Agent::find($AID)->supports('upgrade') ? 'yes' : 'no';")" = yes ] || fail "agent didn't report the upgrade capability"
say "v0.0.1 online and reports it can upgrade ✓"

send_upgrade() { q "(new \App\Jobs\PrepareAgentUpgradeJob($AID, '$1'))->handle(app(\App\Services\Agent\AgentReleases::class));" >/dev/null; }

# --- 1. a good upgrade -------------------------------------------------------
say "upgrading to v0.0.2"
send_upgrade v0.0.2
wait_for 40 '[ "$(field version)" = v0.0.2 ]' || fail "agent didn't come back on v0.0.2"
say "agent reconnected on v0.0.2 ✓ (waiting for it to prove itself healthy)"
wait_for 100 '[ "$(field upgrade_status)" = done ]' || fail "v0.0.2 never reported healthy (status: $(field upgrade_status))"
[ -x "$WORK/base/mymate-agent" ] && "$WORK/base/mymate-agent" version | grep -q v0.0.1 || fail "the installed binary was modified"
say "v0.0.2 healthy, installed binary untouched ✓"

# --- 2. a bad upgrade rolls back by itself ------------------------------------
say "sending a broken v0.0.3"
send_upgrade v0.0.3
wait_for 90 '[ "$(field upgrade_status)" = rolled_back ]' || fail "the broken version wasn't rolled back (status: $(field upgrade_status))"
wait_for 30 '[ "$(field version)" = v0.0.2 ] && [ "$(status)" = online ]' || fail "agent didn't come back online on v0.0.2 (version $(field version), status $(status))"
say "v0.0.3 rolled back, agent back online on v0.0.2: $(field upgrade_message) ✓"

# --- 3. a version that hangs is stopped by the supervisor and rolled back ------
say "sending a v0.0.4 that hangs (health deadline shortened to 10s for the test)"
q "\App\Models\Agent::whereKey($AID)->update(['upgrade_status' => null]);" >/dev/null
send_upgrade v0.0.4
# 3 starts x (10s deadline + 30s supervisor grace + 15s for SIGTERM, then a kill)
wait_for 200 '[ "$(field upgrade_status)" = rolled_back ]' || fail "the hung version wasn't rolled back (status: $(field upgrade_status))"
wait_for 30 '[ "$(field version)" = v0.0.2 ] && [ "$(status)" = online ]' || fail "agent didn't come back online after the hung version"
pgrep -f "$WORK/state/bin/v0.0.4" >/dev/null && fail "the hung version is still running"
say "hung v0.0.4 stopped and rolled back, agent online on v0.0.2: $(field upgrade_message) ✓"

printf '\033[32m== AGENT UPGRADE + ROLLBACK TEST PASSED ==\033[0m\n'
