<?php

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Device;
use App\Services\Snmp\SnmpCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * The sensor form's "Test OID" for a device behind a remote agent (#51): the central server
 * usually can't reach the device, so the agent runs the read and answers through the hub.
 *
 * It's a round trip inside a web request, unlike the rest of the agent traffic: the operator
 * clicked a button and is waiting on the answer. Bounded by $timeoutS.
 */
class TestAgentSnmp
{
    private const REPLY_KEY = 'agent:snmp-test:';

    /**
     * @return array{values?: list<string>, error?: string}
     */
    public function __invoke(Device $device, SnmpCredential $cred, string $oid, string $mode, float $timeoutS = 10.0): array
    {
        $agent = Agent::find($device->agent_id);
        if ($agent === null || $agent->status !== AgentStatus::Online || $agent->isDown()) {
            return ['error' => 'The agent for this device is offline, so the OID can\'t be tested from it.'];
        }

        $rid = Str::random(32);
        // Remember which agent may answer, so another agent's reply can't land here.
        Cache::put(self::REPLY_KEY.$rid.':agent', $agent->id, now()->addMinute());
        Redis::publish(DispatchAgentJobs::CHANNEL, json_encode([
            'agent_id' => $agent->id,
            'snmp_test' => [
                'request_id' => $rid,
                'ip' => $device->mgmt_ip,
                'community' => $cred->community,
                'snmp' => DispatchAgentJobs::snmpAuth($cred),
                'oid' => $oid,
                'mode' => $mode,
            ],
        ]));

        $deadline = microtime(true) + $timeoutS;
        do {
            $reply = Cache::pull(self::REPLY_KEY.$rid);
            if (is_array($reply)) {
                return $reply;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return ['error' => 'The agent didn\'t answer in time. Check it is online and can reach the device.'];
    }

    /**
     * The hub's side: store an agent's snmp_test_result for the waiting request, if that agent
     * is the one the request went to.
     *
     * @param  array<string, mixed>  $msg
     */
    public static function recordReply(int $agentId, array $msg): void
    {
        $rid = (string) ($msg['request_id'] ?? '');
        if ($rid === '' || (int) Cache::get(self::REPLY_KEY.$rid.':agent') !== $agentId) {
            return;
        }
        $error = trim((string) ($msg['error'] ?? ''));
        $reply = $error !== ''
            ? ['error' => $error]
            : ['values' => array_values(array_map('strval', array_filter((array) ($msg['values'] ?? []), 'is_scalar')))];
        Cache::put(self::REPLY_KEY.$rid, $reply, now()->addMinute());
        Cache::forget(self::REPLY_KEY.$rid.':agent');
    }
}
