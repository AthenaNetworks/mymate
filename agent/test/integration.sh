#!/usr/bin/env bash
#
# Agent <-> hub integration test: proves the real Go agent connects to the hub over the
# WebSocket, is marked online, DETECTS a dropped hub and RECONNECTS when it comes back.
#
# Run from the repo root with a working app (composer deps, .env with APP_KEY, migrated DB,
# Redis) and Go on PATH:
#     agent/test/integration.sh
#
# Exit 0 on success. Used by CI (.github/workflows/ci.yml) and runnable locally.
set -u
cd "$(dirname "${BASH_SOURCE[0]}")/../.." # repo root
PORT="${HUB_PORT:-9401}"
BIN=agent/bin/mymate-agent
HUB=""
AGENT=""
AID=""
SID=""

q() { php artisan tinker --execute="$1" 2>/dev/null | tail -1; }
say() { printf '\033[36m>>>\033[0m %s\n' "$*"; }
fail() {
    printf '\033[31mFAIL:\033[0m %s\n' "$*"
    echo "--- agent log ---"; tail -20 /tmp/agent.log 2>/dev/null
    echo "--- hub log ---"; tail -20 /tmp/agent-hub.log 2>/dev/null
    cleanup
    exit 1
}

cleanup() {
    [ -n "$AGENT" ] && kill "$AGENT" 2>/dev/null
    [ -n "$HUB" ] && kill "$HUB" 2>/dev/null
    [ -n "$SID" ] && q "\App\Models\Subnet::destroy($SID);" >/dev/null 2>&1
    [ -n "$AID" ] && q "\App\Models\Agent::destroy($AID);" >/dev/null 2>&1
}
trap cleanup EXIT

# Wait until `cond` (a shell test) is true, up to $1 seconds.
wait_for() {
    local secs="$1"; shift
    for _ in $(seq 1 "$secs"); do eval "$@" && return 0; sleep 1; done
    return 1
}

status() { q "echo \App\Models\Agent::find($AID)->status->value;"; }

# --- build + enrol -------------------------------------------------------
say "building the agent"
( cd agent && ./build.sh host ) >/dev/null || fail "agent build failed"

TOKEN=$(php artisan mymate:agent:create "ci-integration" 2>&1 | grep -oE 'mma_[A-Za-z0-9]+' | head -1)
[ -n "$TOKEN" ] || fail "could not enrol an agent"
AID=$(q 'echo \App\Models\Agent::where("name","ci-integration")->value("id");')
[ -n "$AID" ] || fail "could not resolve agent id"

start_hub() { php artisan mymate:agent-hub --port="$PORT" >/tmp/agent-hub.log 2>&1 & HUB=$!; }
start_agent() {
    env MYMATE_URL="http://127.0.0.1:$PORT" MYMATE_AGENT_TOKEN="$TOKEN" MYMATE_AGENT_LOG=debug \
        "$BIN" >/tmp/agent.log 2>&1 & AGENT=$!
}

# --- 1. connect ----------------------------------------------------------
say "starting hub + agent"
start_hub; sleep 2
start_agent
wait_for 15 '[ "$(status)" = online ]' || fail "agent never came online"
say "agent is ONLINE ✓"

# --- 1b. discovery round-trip: dispatch a scan -> agent scans -> hub ingests
# Assign the agent a tiny loopback range and trigger a dispatch directly (no loop needed).
# The agent expands the CIDR, ping-sweeps + probes, and returns candidates; the hub stamps
# last_scanned_at on ingest - proving the scan-job transport even where the sandbox blocks
# ICMP (an empty candidate set still round-trips and stamps the subnet).
say "dispatching a discovery scan to the agent"
SID=$(q "echo \App\Models\Subnet::create(['cidr'=>'127.0.0.0/30','label'=>'ci-disc','enabled'=>true,'agent_id'=>$AID])->id;")
[ -n "$SID" ] || fail "could not create the scan subnet"
q "app(\App\Actions\Agent\DispatchAgentJobs::class)();" >/dev/null
scanned() { q "echo \App\Models\Subnet::find($SID)?->last_scanned_at ? 'yes' : 'no';"; }
wait_for 20 '[ "$(scanned)" = yes ]' || fail "agent scan never round-tripped (subnet not stamped)"
say "discovery scan round-tripped and stamped the subnet ✓"

# --- 2. drop the hub -> agent must detect + start reconnecting ------------
say "killing the hub (simulating a dropped connection)"
kill "$HUB" 2>/dev/null; HUB=""
wait_for 15 'grep -qi "reconnecting" /tmp/agent.log' || fail "agent did not detect the disconnect (no 'reconnecting' in log)"
say "agent detected the disconnect and is retrying ✓"

# --- 3. bring the hub back -> agent must RECONNECT (online again) ---------
say "restarting the hub"
start_hub
# The new hub resets stale 'online' -> 'offline' on boot; the agent then reconnects -> online.
wait_for 30 '[ "$(status)" = online ]' || fail "agent did not reconnect after the hub returned"
say "agent RECONNECTED and is ONLINE again ✓"

printf '\033[32m== AGENT CONNECT + RECONNECT TEST PASSED ==\033[0m\n'
