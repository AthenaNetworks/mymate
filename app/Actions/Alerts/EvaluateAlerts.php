<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCondition;
use App\Enums\BackupStatus;
use App\Enums\DeviceStatus;
use App\Enums\DiscoveryStatus;
use App\Enums\UpgradeStatus;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Models\Link;
use App\Models\NetworkInterface;
use App\Support\DeviceScope;
use App\Support\MaintenanceGuard;

/**
 * Evaluate every enabled alert policy. For each policy we compute
 * the set of currently-active conditions (keyed for dedupe) and reconcile against
 * its open `alert_events`: fire+notify the new ones, resolve the gone ones. The
 * "one firing event per dedupe_key" invariant is the anti-spam - a flapping device
 * won't re-notify while its event is still firing.
 */
class EvaluateAlerts
{
    public function __construct(private SendAlert $sender) {}

    public function __invoke(): void
    {
        $guard = new MaintenanceGuard;
        $policies = AlertPolicy::where('enabled', true)->with('transports')->get();

        // Resolve each policy's targeting once (a map scope hits the DB).
        $scopes = [];
        foreach ($policies as $policy) {
            $scopes[$policy->id] = DeviceScope::resolve($policy->scope);
        }

        // Specific-over-general (GitHub #32): a device covered by a narrower policy (map /
        // device / device-type scope) must not also alert from the fleet-wide policy for the
        // same condition, or the operator gets two notifications for one event. Build, per
        // condition, the set of device ids any narrower policy claims; a fleet-wide policy then
        // skips those devices. Two narrower policies never suppress each other, so several
        // map-specific policies on the same map all still fire.
        $claimed = [];
        foreach ($policies as $policy) {
            $ids = $scopes[$policy->id];
            if ($ids === null) {
                continue; // fleet-wide policy claims nothing specifically
            }
            $cond = $policy->condition->value;
            $claimed[$cond] ??= [];
            foreach ($ids as $id) {
                $claimed[$cond][$id] = true;
            }
        }

        foreach ($policies as $policy) {
            $suppress = $scopes[$policy->id] === null
                ? ($claimed[$policy->condition->value] ?? [])
                : [];
            $this->reconcile($policy, $guard, $suppress);
        }
    }

    /**
     * @param  array<int, bool>  $suppressDeviceIds  device ids a narrower policy already owns
     */
    private function reconcile(AlertPolicy $policy, MaintenanceGuard $guard, array $suppressDeviceIds = []): void
    {
        $active = $this->activeConditions($policy);
        if ($suppressDeviceIds !== []) {
            $active = $this->dropClaimedDevices($active, $suppressDeviceIds);
        }
        // Sustained-duration gate: a
        // breach must hold for this many minutes before it fires, AND a recovery must hold
        // for the same window before it resolves+notifies. 0 (default) = instant both ways -
        // the original behaviour.
        $durationMin = max(0, (int) ($policy->params['duration_minutes'] ?? 0));
        $notifyRecovery = (bool) ($policy->params['notify_recovery'] ?? true);

        // Open events: `pending` = breaching but not yet sustained; `firing` = notified and
        // still (or recently) active; `resolving` = condition cleared, waiting out the
        // recovery window before it's treated as a real recovery.
        $open = AlertEvent::where('alert_policy_id', $policy->id)
            ->whereIn('status', ['pending', 'firing', 'resolving'])->get()->keyBy('dedupe_key');

        foreach ($active as $key => $message) {
            // Maintenance window: freeze this device's alerts - don't fire or promote while
            // it's suppressed (its existing events, if any, stay as they are).
            if ($this->coveredByMaintenance($key, $guard)) {
                continue;
            }

            $event = $open->get($key);

            if ($event === null) {
                // First sighting of this breach.
                if ($durationMin === 0) {
                    $fired = AlertEvent::create([
                        'alert_policy_id' => $policy->id,
                        'dedupe_key' => $key,
                        'status' => 'firing',
                        'message' => $message,
                        'breach_started_at' => now(),
                        'fired_at' => now(),
                    ]);
                    $this->sender->send($policy, $fired);
                } else {
                    // Start the clock - don't notify yet.
                    AlertEvent::create([
                        'alert_policy_id' => $policy->id,
                        'dedupe_key' => $key,
                        'status' => 'pending',
                        'message' => $message,
                        'breach_started_at' => now(),
                    ]);
                }

                continue;
            }

            // Sustained long enough -> promote pending to firing + notify.
            if ($event->status === 'pending'
                && $event->breach_started_at !== null
                && $event->breach_started_at->copy()->addMinutes($durationMin)->isPast()) {
                $event->update(['status' => 'firing', 'message' => $message, 'fired_at' => now()]);
                $this->sender->send($policy, $event);
            } elseif ($event->status === 'resolving') {
                // Re-breached before the recovery window elapsed - it never really recovered.
                // Revert to firing; no new down notification (dedupe - same ongoing outage)
                // and no recovery notification (it didn't actually resolve).
                $event->update(['status' => 'firing', 'message' => $message, 'recovery_started_at' => null]);
            }
            // Already firing -> keep (dedupe; no re-notify while the condition holds).
        }

        // Conditions that cleared. A `pending` event never fired, so it's just dropped -
        // no down message, no recovery message for a blip that was too short to count.
        foreach ($open as $key => $event) {
            if (array_key_exists($key, $active)) {
                continue;
            }
            // Under maintenance: leave the event frozen rather than resolving it (a recovery
            // that lands during planned work isn't a real recovery yet, and mustn't notify).
            if ($this->coveredByMaintenance($key, $guard)) {
                continue;
            }
            if ($event->status === 'pending') {
                $event->delete();

                continue;
            }
            if ($event->status === 'firing') {
                if ($durationMin === 0) {
                    $event->update(['status' => 'resolved', 'resolved_at' => now()]);
                    if ($notifyRecovery) {
                        $this->sender->sendRecovery($policy, $event);
                    }
                } else {
                    // Start the recovery clock - don't notify yet.
                    $event->update(['status' => 'resolving', 'recovery_started_at' => now()]);
                }

                continue;
            }
            // status === 'resolving': sustained clear long enough -> resolve + notify.
            if ($event->recovery_started_at !== null
                && $event->recovery_started_at->copy()->addMinutes($durationMin)->isPast()) {
                $event->update(['status' => 'resolved', 'resolved_at' => now()]);
                if ($notifyRecovery) {
                    $this->sender->sendRecovery($policy, $event);
                }
            }
            // Else still within the recovery window - keep waiting.
        }
    }

    /** @return array<string, string> dedupe_key => human message */
    private function activeConditions(AlertPolicy $policy): array
    {
        // Targeting: null = fleet-wide, else the in-scope device id set.
        $scope = $this->scopeDeviceIds($policy);

        return match ($policy->condition) {
            // Dependency suppression is on by default - opt out per policy.
            AlertCondition::DeviceDown => $this->downDevices((bool) ($policy->params['suppress_dependent'] ?? true), $scope),
            AlertCondition::HighUtil => $this->highUtil((float) ($policy->params['threshold'] ?? 90), $scope),
            AlertCondition::LowThroughput => $this->lowThroughput((float) ($policy->params['threshold'] ?? 1), $scope),
            AlertCondition::InterfaceDown => $this->interfacesDown($scope),
            AlertCondition::UpgradeFailed => $this->failedUpgrades($scope),
            // Discovery candidates aren't devices yet -> device-scope doesn't apply; always fleet-wide.
            AlertCondition::NewDiscovery => $this->newCandidates(),
            AlertCondition::BackupFailed => $this->failedBackups($scope),
            AlertCondition::HighMetric => $this->highMetric(
                (string) ($policy->params['metric'] ?? 'cpu'),
                (float) ($policy->params['threshold'] ?? 90),
                $scope,
            ),
            AlertCondition::ProbeDown => $this->probesDown($scope),
            AlertCondition::ProbeSlow => $this->slowProbes((float) ($policy->params['threshold'] ?? 1000), $scope),
        };
    }

    /**
     * Service probes whose latest response time is at/over the threshold (ms). Only up probes -
     * a down one is the ProbeDown condition's job. Maintenance-aware key.
     *
     * @param  list<int>|null  $scope
     * @return array<string, string>
     */
    private function slowProbes(float $thresholdMs, ?array $scope): array
    {
        $out = [];
        $inScope = $scope === null ? null : array_flip($scope);

        $probes = \App\Models\Probe::where('enabled', true)
            ->where('status', DeviceStatus::Up)
            ->whereNotNull('latency_ms')
            ->where('latency_ms', '>=', $thresholdMs)
            ->with('device:id,name,mgmt_ip')
            ->get();

        foreach ($probes as $probe) {
            if ($inScope !== null && ! isset($inScope[$probe->device_id])) {
                continue;
            }
            $dev = self::label($probe->device?->name ?? "device {$probe->device_id}", $probe->device?->mgmt_ip);
            $ms = round((float) $probe->latency_ms);
            $out["device:{$probe->device_id}:probe:{$probe->id}:slow"] = "Probe \"{$probe->name}\" response time {$ms}ms on {$dev} (over ".round($thresholdMs)."ms).";
        }

        return $out;
    }

    /**
     * Service probes (GitHub #19) currently down. Keyed as `device:{id}:probe:{id}` so a
     * maintenance window on the device suppresses its probe alerts too.
     *
     * @param  list<int>|null  $scope
     * @return array<string, string>
     */
    private function probesDown(?array $scope): array
    {
        $out = [];
        $inScope = $scope === null ? null : array_flip($scope);

        $probes = \App\Models\Probe::where('enabled', true)
            ->where('status', DeviceStatus::Down)
            ->with('device:id,name,mgmt_ip')
            ->get();

        foreach ($probes as $probe) {
            if ($inScope !== null && ! isset($inScope[$probe->device_id])) {
                continue;
            }
            $dev = self::label($probe->device?->name ?? "device {$probe->device_id}", $probe->device?->mgmt_ip);
            $why = $probe->message ? " ({$probe->message})" : '';
            $out["device:{$probe->device_id}:probe:{$probe->id}"] = "Probe \"{$probe->name}\" is down on {$dev}{$why}.";
        }

        return $out;
    }

    /**
     * Resolve a policy's targeting bag to the set of device ids it covers,
     * or `null` for fleet-wide. An explicit scope that matches nothing -> empty array (no alerts).
     *
     * @return array<int>|null
     */
    private function scopeDeviceIds(AlertPolicy $policy): ?array
    {
        return DeviceScope::resolve($policy->scope);
    }

    /**
     * Drop active conditions whose device is already owned by a narrower policy of the same
     * condition, so a fleet-wide policy doesn't double-notify (GitHub #32). Only device-keyed
     * entries (`device:{id}...`) are affected; link/discovery keys have no single owning device
     * and are left untouched.
     *
     * @param  array<string, string>  $active
     * @param  array<int, bool>  $claimed
     * @return array<string, string>
     */
    private function dropClaimedDevices(array $active, array $claimed): array
    {
        foreach ($active as $key => $_message) {
            if (preg_match('/^device:(\d+)/', $key, $m) === 1 && isset($claimed[(int) $m[1]])) {
                unset($active[$key]);
            }
        }

        return $active;
    }

    /** A device label carrying its management IP alongside the hostname (GitHub #32): "name
     *  (1.2.3.4)" when an IP is known, else just the name. */
    private static function label(?string $name, ?string $ip): string
    {
        $name = ($name === null || $name === '') ? 'device' : $name;

        return ($ip !== null && $ip !== '') ? "{$name} ({$ip})" : $name;
    }

    /**
     * Is this dedupe key's device under a maintenance window? Device-based conditions key as
     * `device:{id}...`; link/discovery keys don't reference a single device, so they're never
     * suppressed by maintenance.
     */
    private function coveredByMaintenance(string $key, MaintenanceGuard $guard): bool
    {
        if (! $guard->any()) {
            return false;
        }

        return preg_match('/^device:(\d+)/', $key, $m) === 1 && $guard->covers((int) $m[1]);
    }

    /**
     * Down devices, with optional dependency-aware suppression. When
     * `$suppressDependent`, a device that is down *behind a down ancestor* (parent chain)
     * is treated as collateral and not alerted - only the root-cause devices (the highest
     * down node on each branch) fire. This is The Dude's anti-storm behaviour: one upstream
     * failure raises one alert, not hundreds for everything behind it.
     *
     * Targeting: `$scope` (null = all) limits which devices may alert,
     * but ancestor status is still read fleet-wide so an out-of-scope down parent still
     * suppresses an in-scope child.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function downDevices(bool $suppressDependent, ?array $scope): array
    {
        $devices = Device::get(['id', 'name', 'mgmt_ip', 'parent_device_id', 'status']);
        $inScope = $scope === null ? null : array_flip($scope);

        /** @var array<int, DeviceStatus> $statusById */
        $statusById = [];
        /** @var array<int, int|null> $parentById */
        $parentById = [];
        foreach ($devices as $d) {
            $statusById[$d->id] = $d->status;
            $parentById[$d->id] = $d->parent_device_id;
        }

        // True if any ancestor up the parent chain is down (cycle-guarded).
        $hasDownAncestor = function (int $id) use ($parentById, $statusById): bool {
            $seen = [];
            $p = $parentById[$id] ?? null;
            while ($p !== null && ! isset($seen[$p])) {
                $seen[$p] = true;
                if (($statusById[$p] ?? null) === DeviceStatus::Down) {
                    return true;
                }
                $p = $parentById[$p] ?? null;
            }

            return false;
        };

        $out = [];
        foreach ($devices as $d) {
            if ($inScope !== null && ! isset($inScope[$d->id])) {
                continue;
            }
            if ($statusById[$d->id] !== DeviceStatus::Down) {
                continue;
            }
            if ($suppressDependent && $hasDownAncestor($d->id)) {
                continue;
            }
            $out["device:{$d->id}"] = 'Device '.self::label($d->name, $d->mgmt_ip).' is down.';
        }

        return $out;
    }

    /**
     * Links whose utilisation is at/over the threshold.
     * Link util = the busier direction's raw bps / the link's effective speed (override,
     * else the slower end). Links without a known speed can't breach (util null).
     *
     * Targeting: with a `$scope`, only links with at least one endpoint
     * device in scope are considered.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function highUtil(float $threshold, ?array $scope): array
    {
        $out = [];
        $inScope = $scope === null ? null : array_flip($scope);
        $links = Link::with(['aInterface', 'bInterface', 'aDevice:id,name', 'bDevice:id,name'])->get();
        foreach ($links as $l) {
            if ($inScope !== null && ! isset($inScope[$l->a_device_id]) && ! isset($inScope[$l->b_device_id])) {
                continue;
            }
            $u = $l->util();
            if ($u === null || $u < $threshold) {
                continue;
            }
            $a = $l->aDevice?->name ?? "device {$l->a_device_id}";
            $b = $l->bDevice?->name ?? "device {$l->b_device_id}";
            $out["link:{$l->id}"] = 'High utilisation '.((int) round($u))."% on link {$a} <-> {$b}.";
        }

        return $out;
    }

    /**
     * Links whose live throughput has fallen below a floor (Mbps) - e.g. a customer circuit
     * that should always carry traffic going quiet. Skips a link with a down end (device-down
     * covers that, and a dead link is 0 by definition) and one with no measurement yet (can't
     * judge). The busiest direction is what's compared, so a link is only "low" when BOTH
     * directions are below the floor.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function lowThroughput(float $thresholdMbps, ?array $scope): array
    {
        $out = [];
        $inScope = $scope === null ? null : array_flip($scope);
        $floorBps = $thresholdMbps * 1_000_000;
        $links = Link::with(['aInterface', 'bInterface', 'aDevice:id,name,status', 'bDevice:id,name,status'])->get();
        foreach ($links as $l) {
            if ($inScope !== null && ! isset($inScope[$l->a_device_id]) && ! isset($inScope[$l->b_device_id])) {
                continue;
            }
            if ($l->aDevice?->status === DeviceStatus::Down || $l->bDevice?->status === DeviceStatus::Down) {
                continue; // a down end can't be judged for "low" traffic
            }
            $bps = $this->linkBps($l);
            if ($bps === null || $bps >= $floorBps) {
                continue; // no reading, or above the floor
            }
            $a = $l->aDevice?->name ?? "device {$l->a_device_id}";
            $b = $l->bDevice?->name ?? "device {$l->b_device_id}";
            $out["link:{$l->id}"] = 'Low throughput '.$this->fmtBps($bps).' (below '.$this->fmtBps($floorBps).") on link {$a} <-> {$b}.";
        }

        return $out;
    }

    /**
     * Interfaces that are operationally down while their device is up - a customer/edge port
     * dropping even though the box (and its uplink) stay reachable. Device-down is handled
     * separately, so a down device's ports are skipped (its ports are moot, and it avoids an
     * alert storm). Only ports the poller marked 'down' fire; a null (unknown/not polled) port
     * never does. Keyed `device:{id}:iface:{id}` so maintenance windows suppress it.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function interfacesDown(?array $scope): array
    {
        $out = [];
        $inScope = $scope === null ? null : array_flip($scope);

        // Only ports on UP devices - a down device is the device-down policy's job.
        $upDevices = Device::where('status', DeviceStatus::Up)->get(['id', 'name', 'mgmt_ip'])->keyBy('id');
        if ($upDevices->isEmpty()) {
            return $out;
        }

        $ifaces = NetworkInterface::where('oper_status', 'down')
            ->whereIn('device_id', $upDevices->keys())
            ->get(['id', 'device_id', 'name']);

        foreach ($ifaces as $if) {
            if ($inScope !== null && ! isset($inScope[$if->device_id])) {
                continue;
            }
            $device = $upDevices->get($if->device_id);
            $dev = self::label($device?->name ?? "device {$if->device_id}", $device?->mgmt_ip);
            $out["device:{$if->device_id}:iface:{$if->id}"] = "Interface {$if->name} is down on {$dev}.";
        }

        return $out;
    }

    /** Busiest measured throughput (bps) across a link's two ends; null when neither reported. */
    private function linkBps(Link $l): ?int
    {
        $vals = [];
        foreach ([$l->aInterface, $l->bInterface] as $if) {
            if ($if === null) {
                continue;
            }
            foreach ([$if->bps_in, $if->bps_out] as $b) {
                if ($b !== null) {
                    $vals[] = (int) $b;
                }
            }
        }

        return $vals === [] ? null : max($vals);
    }

    /** "6.1G" / "730M" / "12k" / "0" - compact bits-per-second for an alert message. */
    private function fmtBps(float $bps): string
    {
        if ($bps >= 1e9) {
            return round($bps / 1e9, 1).'G';
        }
        if ($bps >= 1e6) {
            return round($bps / 1e6, $bps < 1e7 ? 1 : 0).'M';
        }
        if ($bps >= 1e3) {
            return round($bps / 1e3).'k';
        }

        return (string) (int) $bps;
    }

    /**
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function failedUpgrades(?array $scope): array
    {
        $out = [];
        $query = Device::where('upgrade_status', UpgradeStatus::Failed);
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }
        foreach ($query->get(['id', 'name', 'mgmt_ip', 'upgrade_at', 'upgrade_message']) as $d) {
            // Key by the failure timestamp so a *new* failure re-fires after resolve.
            $stamp = $d->upgrade_at?->timestamp ?? 0;
            $out["device:{$d->id}:upgrade:{$stamp}"] = 'Upgrade failed on '.self::label($d->name, $d->mgmt_ip).': '.($d->upgrade_message ?? 'unknown error').'.';
        }

        return $out;
    }

    /**
     * Devices whose last config backup failed. One event per device (dedupe on device id),
     * so a device that keeps failing its scheduled backup alerts once and resolves when a
     * backup next succeeds - not on every scheduled run.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function failedBackups(?array $scope): array
    {
        $out = [];
        $query = Device::where('backup_status', BackupStatus::Failed);
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }
        foreach ($query->get(['id', 'name', 'mgmt_ip', 'backup_message']) as $d) {
            $out["device:{$d->id}:backup"] = 'Config backup failed on '.self::label($d->name, $d->mgmt_ip).': '.($d->backup_message ?: 'unknown error').'.';
        }

        return $out;
    }

    /**
     * Devices whose chosen resource metric (cpu/mem/temp) is at/over the threshold. A stale
     * reading is ignored: a device that stopped reporting keeps its last value, and alerting
     * on a frozen number would be wrong - DeviceDown covers unreachable gear. One event per
     * device+metric so CPU and temperature policies don't collide.
     *
     * @param  array<int>|null  $scope
     * @return array<string, string>
     */
    private function highMetric(string $metric, float $threshold, ?array $scope): array
    {
        // column, human label, unit, freshness column (cpu/mem/temp ride the metrics poll;
        // latency/loss ride the ping sweep, so each is judged fresh against its own stamp).
        [$col, $label, $unit, $stamp] = match ($metric) {
            'mem' => ['mem_used_pct', 'memory', '%', 'metrics_at'],
            'temp' => ['temp_c', 'temperature', '°C', 'metrics_at'],
            'latency' => ['rtt_ms', 'latency', 'ms', 'ping_at'],
            'loss' => ['loss_pct', 'packet loss', '%', 'ping_at'],
            default => ['cpu_pct', 'CPU', '%', 'metrics_at'], // 'cpu'
        };

        // Freshness: ignore readings older than ~10x their cadence (floor 10 min) so a device
        // that stopped reporting doesn't alert on a frozen value.
        $cadence = $stamp === 'ping_at'
            ? max(5, (int) config('mymate.ping.history_interval', 60))
            : max(5, (int) config('mymate.device_metrics.interval', 30));
        $freshAfter = now()->subSeconds(max(600, $cadence * 10));

        $query = Device::whereNotNull($col)
            ->where($col, '>=', $threshold)
            ->where($stamp, '>=', $freshAfter);
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }

        $out = [];
        foreach ($query->get(['id', 'name', 'mgmt_ip', $col]) as $d) {
            $val = (int) round((float) $d->{$col});
            $out["device:{$d->id}:metric:{$metric}"] = "High {$label} {$val}{$unit} on ".self::label($d->name, $d->mgmt_ip).'.';
        }

        return $out;
    }

    /** @return array<string, string> */
    private function newCandidates(): array
    {
        $out = [];
        foreach (DiscoveryCandidate::where('status', DiscoveryStatus::New)->get(['id', 'ip', 'sysname']) as $c) {
            $out["candidate:{$c->id}"] = 'New device discovered: '.($c->sysname ?: $c->ip)." ({$c->ip}).";
        }

        return $out;
    }
}
