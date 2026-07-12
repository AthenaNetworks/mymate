<?php

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Subnet;
use Illuminate\Support\Facades\Redis;

/**
 * Build and publish poll/scan work for each ONLINE agent. The loop calls this on the poll
 * cadence; the agent hub ({@see \App\Console\Commands\AgentHubCommand}) is subscribed to the
 * Redis channel and forwards each job down that agent's WebSocket.
 *
 * We publish (rather than queue) because a job is only useful while the agent is connected -
 * if it's offline the work is simply skipped this tick and picked up next tick once it's back.
 *
 * NOTE: device credentials for the agent's pollers travel in the job (the pollers consume
 * them). This builds the target structure - which devices to ping, which interfaces to poll,
 * which subnets to scan.
 */
class DispatchAgentJobs
{
    /** Redis pub/sub channel the hub listens on. Payload: {agent_id, poll, scan}. */
    public const CHANNEL = 'mymate:agent-dispatch';

    /** @return int number of agents dispatched to */
    public function __invoke(): int
    {
        $agents = Agent::where('status', AgentStatus::Online)->pluck('id');
        $count = 0;

        foreach ($agents as $agentId) {
            $job = $this->buildJob((int) $agentId);
            if ($job['poll']['ping'] === [] && $job['scan']['subnets'] === []) {
                continue; // nothing assigned to this agent yet
            }
            Redis::publish(self::CHANNEL, json_encode($job));
            $count++;
        }

        return $count;
    }

    /**
     * The work for one agent: ping every assigned device (up/down), SNMP-poll those with an
     * SNMP credential, and scan its subnets. Credentials are decrypted here and travel over
     * the TLS tunnel - the agent needs them to poll (inherent to the agent model), same as a
     * Zabbix proxy. RouterOS targets are built the same way.
     *
     * @return array<string,mixed>
     */
    public function buildJob(int $agentId): array
    {
        $devices = Device::where('agent_id', $agentId)
            ->where('monitored', true)
            ->with(['interfaces:id,device_id,if_index,name', 'credential'])
            ->get();

        $ping = [];
        $snmp = [];
        $routeros = [];
        foreach ($devices as $d) {
            $ping[] = ['device_id' => $d->id, 'ip' => $d->mgmt_ip];

            if ($d->poll_method === PollMethod::Snmp && $d->credential?->type === 'snmp') {
                $snmp[] = [
                    'device_id' => $d->id,
                    'ip' => $d->mgmt_ip,
                    'community' => (string) $d->credential->snmp_community,
                    'interfaces' => $d->interfaces->map(fn ($i) => [
                        'interface_id' => $i->id,
                        'if_index' => $i->if_index,
                    ])->all(),
                ];
            } elseif ($d->poll_method === PollMethod::RouterOs && $d->credential?->type === 'routeros') {
                $routeros[] = [
                    'device_id' => $d->id,
                    'ip' => $d->mgmt_ip,
                    'username' => (string) $d->credential->username,
                    'password' => (string) $d->credential->password,
                    'api_port' => (int) ($d->credential->api_port ?: 8728),
                    'interfaces' => $d->interfaces->map(fn ($i) => [
                        'interface_id' => $i->id,
                        'name' => $i->name,
                    ])->all(),
                ];
            }
        }

        // Only DUE subnets - the agent scans on the per-subnet cadence, same rule the
        // central loop uses (never scanned, or last scan older than scan_interval_s).
        // last_scanned_at is stamped when the agent's scan result is ingested.
        $now = now();
        $subnets = Subnet::where('agent_id', $agentId)->where('enabled', true)->get()
            ->filter(fn (Subnet $s): bool => $s->last_scanned_at === null
                || $s->last_scanned_at->copy()->addSeconds(max(1, $s->scan_interval_s))->lessThanOrEqualTo($now))
            ->map(fn (Subnet $s) => ['subnet_id' => $s->id, 'cidr' => $s->cidr])->values()->all();

        return [
            'agent_id' => $agentId,
            'poll' => ['ping' => $ping, 'snmp' => $snmp, 'routeros' => $routeros],
            'scan' => [
                'subnets' => $subnets,
                // credential pool for the agent to try. no point sending it if theres nothing
                // to scan. agent probes locally and only sends matched IDs back.
                'credentials' => $subnets === [] ? ['snmp' => [], 'routeros' => []] : $this->credentialPool(),
            ],
        ];
    }

    /**
     * The decrypted credential pool the agent tries against discovery responders. Same pool
     * the central HostProber uses; secrets travel over the TLS tunnel (inherent to discovery).
     *
     * @return array{snmp: list<array<string,mixed>>, routeros: list<array<string,mixed>>}
     */
    private function credentialPool(): array
    {
        $creds = Credential::all();

        return [
            'snmp' => $creds->where('type', 'snmp')->filter(fn ($c) => (string) $c->snmp_community !== '')
                ->map(fn ($c) => ['credential_id' => $c->id, 'community' => (string) $c->snmp_community])
                ->values()->all(),
            'routeros' => $creds->where('type', 'routeros')->filter(fn ($c) => (string) $c->username !== '')
                ->map(fn ($c) => [
                    'credential_id' => $c->id,
                    'username' => (string) $c->username,
                    'password' => (string) $c->password,
                    'api_port' => (int) ($c->api_port ?: 8728),
                ])->values()->all(),
        ];
    }
}
