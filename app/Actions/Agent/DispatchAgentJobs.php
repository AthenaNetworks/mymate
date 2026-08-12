<?php

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Enums\PollMethod;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Subnet;
use App\Services\Polling\DeviceMetricProfiles;
use App\Services\Snmp\SnmpCredential;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(private DeviceMetricProfiles $profiles) {}

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

        // Discovery cadence: ask the agent to (re)walk interfaces + facts for its SNMP devices
        // once per discover_interval, gated per agent in cache. Cache-empty counts as due, so a
        // freshly assigned device is discovered on the next tick. Mirrors the central discovery
        // cadence, just routed through the agent (#33).
        $discoverInterval = max(60, (int) config('mymate.poll.discover_interval', 600));
        $discoverKey = "agent:{$agentId}:last_discover";
        $discoverDue = (now()->timestamp - (int) Cache::get($discoverKey, 0)) >= $discoverInterval;
        if ($discoverDue) {
            Cache::put($discoverKey, now()->timestamp, now()->addDay());
        }

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
                    'snmp' => self::snmpAuth(SnmpCredential::fromCredential($d->credential)),
                    'interfaces' => $d->interfaces->map(fn ($i) => [
                        'interface_id' => $i->id,
                        'if_index' => $i->if_index,
                    ])->all(),
                    'metrics' => $this->metricsTarget($d),
                    'discover' => $discoverDue,
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
        $due = Subnet::where('agent_id', $agentId)->where('enabled', true)->get()
            ->filter(function (Subnet $s) use ($now): bool {
                // Skip a subnet whose sweep is already in flight (claimed below, or the agent is
                // reporting progress) so the loop can't stack overlapping scans of the same range.
                if ($s->scanning_since !== null && $s->scanning_since->diffInSeconds($now) < 300) {
                    return false;
                }

                return $s->last_scanned_at === null
                    || $s->last_scanned_at->copy()->addSeconds(max(1, $s->scan_interval_s))->lessThanOrEqualTo($now);
            });

        // Claim them: mark scanning now so the next tick skips them until the agent's result
        // clears it (or it goes stale after 300s if the agent never reports back).
        if ($due->isNotEmpty()) {
            Subnet::whereIn('id', $due->pluck('id'))->update(['scanning_since' => $now]);
        }

        $subnets = $due->map(fn (Subnet $s) => ['subnet_id' => $s->id, 'cidr' => $s->cidr])->values()->all();

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
     * The v3 USM parameters (or empty for v1/v2c) the agent needs to authenticate. Mirrors the
     * central SnmpCredential value object so an agent-polled device authenticates identically.
     *
     * @return array<string,string>
     */
    /** True when an SNMP credential can actually authenticate (community, or full v3 USM). */
    private static function snmpCredUsable(Credential $c): bool
    {
        return SnmpCredential::fromCredential($c)->isUsable();
    }

    private static function snmpAuth(SnmpCredential $cred): array
    {
        if ($cred->version !== '3') {
            return ['version' => $cred->version];
        }

        return [
            'version' => '3',
            'sec_name' => $cred->secName,
            'sec_level' => $cred->secLevel,
            'auth_protocol' => $cred->authProtocol,
            'auth_passphrase' => $cred->authPassphrase,
            'priv_protocol' => $cred->privProtocol,
            'priv_passphrase' => $cred->privPassphrase,
        ];
    }

    /**
     * The cpu/mem/temp OID profile the agent should read for this device - the same profile the
     * central SnmpDeviceMetricsDriver uses, plus the shared hrStorage columns. Null when the
     * device resolves to no usable metrics profile.
     *
     * @return array<string,mixed>|null
     */
    private function metricsTarget(Device $device): ?array
    {
        $p = $this->profiles->for($device);
        $hr = config('mymate.device_metrics.hrstorage', []);

        $target = array_filter([
            'cpu_walk' => $p['cpu_walk'] ?? null,
            'cpu_oids' => $p['cpu_oids'] ?? null,
            'mem' => $p['mem'] ?? null,
            'mem_used_walk' => $p['mem_used_walk'] ?? null,
            'mem_free_walk' => $p['mem_free_walk'] ?? null,
            'temp_walk' => $p['temp_walk'] ?? null,
            'temp_oids' => $p['temp_oids'] ?? null,
            'temp_divisor' => $p['temp_divisor'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);

        if (($p['mem'] ?? null) === 'hrstorage') {
            $target['hr_descr'] = $hr['descr'] ?? null;
            $target['hr_size'] = $hr['size'] ?? null;
            $target['hr_used'] = $hr['used'] ?? null;
        }

        // Nothing to read for cpu/mem/temp -> no metrics target (still ping + throughput).
        $hasCpu = isset($target['cpu_walk']) || isset($target['cpu_oids']);
        $hasMem = isset($target['mem']);
        $hasTemp = isset($target['temp_walk']) || isset($target['temp_oids']);

        return $hasCpu || $hasMem || $hasTemp ? $target : null;
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
            'snmp' => $creds->where('type', 'snmp')->filter(fn ($c) => self::snmpCredUsable($c))
                ->map(fn ($c) => [
                    'credential_id' => $c->id,
                    'community' => (string) $c->snmp_community,
                    'snmp' => self::snmpAuth(SnmpCredential::fromCredential($c)),
                ])
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
