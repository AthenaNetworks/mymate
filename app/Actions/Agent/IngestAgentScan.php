<?php

namespace App\Actions\Agent;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\Subnet;
use App\Support\EngineLog;

/**
 * Takes a remote agent's scan results and drops them into the same review queue the central
 * scanner uses. New, identified responders become discovery_candidate rows (we never auto-add
 * a device), and we stamp last_scanned_at on each subnet so its cadence moves on.
 *
 * The agent already did the ping sweep + credential probing locally (inside the mgmt network),
 * so all we do here is apply the same queueing rules as {@see \App\Actions\Discovery\ScanSubnet}:
 * skip IPs that are already devices, bump last_seen for ones we've already got (don't re-trial,
 * don't bring back an ignored candidate), and only queue hosts we actually identified over
 * snmp/routeros. A host that just answers ping has nothing to poll so it's no use to us.
 *
 * Security: an agent can only report on subnets that are assigned to it. Anything else we drop.
 *
 * @phpstan-type ScanCandidate array{ip:string, sysname?:string, method?:string, credential_id?:int}
 */
class IngestAgentScan
{
    /** @param array<string,mixed> $payload */
    public function __invoke(Agent $agent, array $payload): void
    {
        $subnetResults = $payload['subnets'] ?? [];
        if (! is_array($subnetResults) || $subnetResults === []) {
            return;
        }

        // Only this agent's subnets, indexed by id - a buggy/compromised agent can't scan
        // (or restamp) a subnet that isn't delegated to it.
        $wanted = array_filter(array_map(fn ($s) => $s['subnet_id'] ?? null, $subnetResults));
        $subnets = Subnet::where('agent_id', $agent->id)->whereIn('id', $wanted)->get()->keyBy('id');

        foreach ($subnetResults as $result) {
            $subnet = $subnets->get($result['subnet_id'] ?? 0);
            if ($subnet === null) {
                continue; // not this agent's subnet - ignore
            }
            $this->ingestSubnet($subnet, is_array($result['candidates'] ?? null) ? $result['candidates'] : []);
        }
    }

    /** @param list<array<string,mixed>> $candidates */
    private function ingestSubnet(Subnet $subnet, array $candidates): void
    {
        $now = now();
        $ips = array_values(array_filter(array_map(fn ($c) => $c['ip'] ?? null, $candidates)));

        // IPs that are already devices (skip) and candidates already queued (bump, never dup).
        $deviceIps = Device::whereIn('mgmt_ip', $ips)->pluck('mgmt_ip')->flip();
        $existing = DiscoveryCandidate::whereIn('ip', $ips)->get()->keyBy('ip');

        $new = 0;
        $skipped = 0;
        foreach ($candidates as $c) {
            $ip = (string) ($c['ip'] ?? '');
            if ($ip === '' || $deviceIps->has($ip)) {
                continue;
            }

            $candidate = $existing->get($ip);
            if ($candidate !== null) {
                $candidate->update(['last_seen' => $now]); // seen again - never re-trial / re-status
                continue;
            }

            // Only queue positively-identified hosts (the agent tried the credential pool);
            // a ping-only responder with no SNMP/RouterOS match isn't actionable.
            $method = PollMethod::tryFrom((string) ($c['method'] ?? ''));
            if ($method === null) {
                $skipped++;
                continue;
            }

            DiscoveryCandidate::create([
                'ip' => $ip,
                'status' => DiscoveryStatus::New,
                'sysname' => $c['sysname'] ?? null,
                'detected_method' => $method,
                'matched_credential_id' => isset($c['credential_id']) ? (int) $c['credential_id'] : null,
                'first_seen' => $now,
                'last_seen' => $now,
            ]);
            $new++;
        }

        // Results are in: the sweep is done - clear the live scan state + progress counters.
        $subnet->update([
            'last_scanned_at' => $now,
            'scanning_since' => null,
            'scan_total' => null,
            'scan_swept' => null,
            'scan_found' => null,
        ]);

        EngineLog::info('agent: subnet scanned', [
            'subnet_id' => $subnet->id,
            'cidr' => $subnet->cidr,
            'responders' => count($candidates),
            'new_candidates' => $new,
            'unidentified_skipped' => $skipped,
        ]);
    }
}
