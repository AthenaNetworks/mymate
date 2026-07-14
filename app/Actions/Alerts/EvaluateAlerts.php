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
use App\Models\DeviceMapPosition;
use App\Models\DiscoveryCandidate;
use App\Models\Link;

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
        foreach (AlertPolicy::where('enabled', true)->with('transports')->get() as $policy) {
            $this->reconcile($policy);
        }
    }

    private function reconcile(AlertPolicy $policy): void
    {
        $active = $this->activeConditions($policy);
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
            AlertCondition::UpgradeFailed => $this->failedUpgrades($scope),
            // Discovery candidates aren't devices yet -> device-scope doesn't apply; always fleet-wide.
            AlertCondition::NewDiscovery => $this->newCandidates(),
            AlertCondition::BackupFailed => $this->failedBackups($scope),
            AlertCondition::HighMetric => $this->highMetric(
                (string) ($policy->params['metric'] ?? 'cpu'),
                (float) ($policy->params['threshold'] ?? 90),
                $scope,
            ),
        };
    }

    /**
     * Resolve a policy's targeting bag to the set of device ids it covers,
     * or `null` for fleet-wide. An explicit scope that matches nothing -> empty array (no alerts).
     *
     * @return array<int>|null
     */
    private function scopeDeviceIds(AlertPolicy $policy): ?array
    {
        $scope = $policy->scope ?? [];

        return match ($scope['type'] ?? 'all') {
            'device_type' => Device::where('device_type', $scope['device_type'] ?? '')->pluck('id')->all(),
            'map' => DeviceMapPosition::where('map_id', (int) ($scope['map_id'] ?? 0))->pluck('device_id')->unique()->values()->all(),
            'devices' => array_values(array_map('intval', $scope['device_ids'] ?? [])),
            default => null, // 'all'
        };
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
        $devices = Device::get(['id', 'name', 'parent_device_id', 'status']);
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
            $out["device:{$d->id}"] = "Device {$d->name} is down.";
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
        foreach ($query->get(['id', 'name', 'upgrade_at', 'upgrade_message']) as $d) {
            // Key by the failure timestamp so a *new* failure re-fires after resolve.
            $stamp = $d->upgrade_at?->timestamp ?? 0;
            $out["device:{$d->id}:upgrade:{$stamp}"] = "Upgrade failed on {$d->name}: ".($d->upgrade_message ?? 'unknown error').'.';
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
        foreach ($query->get(['id', 'name', 'backup_message']) as $d) {
            $out["device:{$d->id}:backup"] = "Config backup failed on {$d->name}: ".($d->backup_message ?: 'unknown error').'.';
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
        // column, human label, unit
        [$col, $label, $unit] = match ($metric) {
            'mem' => ['mem_used_pct', 'memory', '%'],
            'temp' => ['temp_c', 'temperature', '°C'],
            default => ['cpu_pct', 'CPU', '%'], // 'cpu'
        };

        // Freshness: ignore readings older than ~10x the metrics cadence (floor 10 min).
        $interval = max(5, (int) config('mymate.device_metrics.interval', 30));
        $freshAfter = now()->subSeconds(max(600, $interval * 10));

        $query = Device::whereNotNull($col)
            ->where($col, '>=', $threshold)
            ->where('metrics_at', '>=', $freshAfter);
        if ($scope !== null) {
            $query->whereIn('id', $scope);
        }

        $out = [];
        foreach ($query->get(['id', 'name', $col]) as $d) {
            $val = (int) round((float) $d->{$col});
            $out["device:{$d->id}:metric:{$metric}"] = "High {$label} {$val}{$unit} on {$d->name}.";
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
