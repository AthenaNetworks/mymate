<?php

namespace App\Actions\Devices;

use App\Actions\Backup\RegisterBackupDevice;
use App\Enums\UpgradeStatus;
use App\Models\AlertEvent;
use App\Models\Device;
use App\Models\DeviceUpgrade;
use App\Models\Outage;
use App\Models\User;
use App\Services\Backup\RustedClient;
use App\Support\BackupSettings;
use App\Support\EngineLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One timeline of what happened to a device (the device page's Events tab, GitHub #28), merged
 * from every place that records something:
 *
 *  - outages: went down / came back (with how long it was out)
 *  - alert events whose dedupe key belongs to the device: fired / resolved
 *  - config backups: each stored version from Rusted (skipped for restricted operators, the
 *    backup endpoints are off limits to them)
 *  - firmware upgrades: every attempt from device_upgrades (RecordUpgradeStatus writes them)
 *  - reboots: every one the metrics poller caught (device_reboots, uptime went backwards), plus
 *    the current boot worked out from the last uptime reading
 *
 * Each source is capped (newest first) before merging, which keeps a noisy device's page cheap.
 */
class GetDeviceEvents
{
    private const CAP = 500;

    public function __construct(
        private readonly RustedClient $rusted,
        private readonly BackupSettings $backups,
    ) {}

    /**
     * @param  list<string>  $types  empty = all
     * @return array{data: list<array<string, mixed>>, meta: array{page:int, per_page:int, total:int, has_more:bool}}
     */
    public function __invoke(Device $device, int $page, int $perPage, array $types, bool $withBackups): array
    {
        $want = fn (string $t) => $types === [] || in_array($t, $types, true);
        $events = [];

        if ($want('outage')) {
            foreach (Outage::where('device_id', $device->id)->latest('started_at')->limit(self::CAP)->get() as $o) {
                $events[] = self::event("outage-{$o->id}-down", 'outage', 'down', $o->started_at, 'Went down', $o->cause);
                if ($o->ended_at !== null) {
                    $events[] = self::event("outage-{$o->id}-up", 'outage', 'up', $o->ended_at, 'Came back up', 'Down for '.self::duration((int) ($o->duration_s ?? $o->started_at->diffInSeconds($o->ended_at, true))));
                }
            }
        }

        if ($want('alert')) {
            $alerts = AlertEvent::with('policy:id,name')
                ->where(fn ($q) => $q->where('dedupe_key', "device:{$device->id}")->orWhere('dedupe_key', 'like', "device:{$device->id}:%"))
                ->where('status', '!=', 'pending')
                ->latest('fired_at')->limit(self::CAP)->get();
            foreach ($alerts as $a) {
                $policy = $a->policy?->name;
                if ($a->fired_at !== null) {
                    $events[] = self::event("alert-{$a->id}-fired", 'alert', 'fired', $a->fired_at, $policy ? "Alert fired: {$policy}" : 'Alert fired', $a->message);
                }
                if ($a->resolved_at !== null) {
                    $events[] = self::event("alert-{$a->id}-resolved", 'alert', 'resolved', $a->resolved_at, $policy ? "Alert resolved: {$policy}" : 'Alert resolved', $a->message);
                }
            }
        }

        if ($want('backup') && $withBackups && $device->backup_enabled && $this->backups->configured()) {
            try {
                $versions = array_slice($this->rusted->versions(RegisterBackupDevice::rustedName($device)), 0, self::CAP);
            } catch (\Throwable $e) {
                EngineLog::warning('device events: backup versions fetch failed', ['device_id' => $device->id, 'error' => $e->getMessage()]);
                $versions = [];
            }
            foreach ($versions as $v) {
                $at = self::parse($v['date'] ?? null);
                if ($at !== null) {
                    $events[] = self::event('backup-'.($v['commit'] ?? $at->timestamp), 'backup', 'changed', $at, 'Config changed', trim(($v['commit'] ?? '').' '.($v['subject'] ?? '')), ['commit' => $v['commit'] ?? null]);
                }
            }
        }

        if ($want('upgrade')) {
            $upgrades = DeviceUpgrade::where('device_id', $device->id)->latest('id')->limit(self::CAP)->get();
            $who = User::whereIn('id', $upgrades->pluck('user_id')->filter()->unique())->pluck('name', 'id');
            foreach ($upgrades as $u) {
                $events[] = self::upgradeEvent($u, $u->user_id !== null ? ($who[$u->user_id] ?? null) : null);
            }
        }

        if ($want('reboot')) {
            // Every reboot the metrics poller caught (uptime went backwards), newest first.
            $seen = [];
            foreach (DB::table('device_reboots')->where('device_id', $device->id)->orderByDesc('booted_at')->limit(self::CAP)->get() as $r) {
                $booted = Carbon::parse($r->booted_at);
                $seen[] = $booted;
                $events[] = self::event("reboot-{$r->id}", 'reboot', 'rebooted', $booted, 'Rebooted',
                    $r->previous_uptime_s !== null ? 'Had been up '.self::duration((int) $r->previous_uptime_s) : null);
            }
            // The current boot from the last uptime reading, unless that's one of the reboots
            // above (same boot, give or take the poll interval).
            if ($device->uptime_seconds !== null && $device->uptime_at !== null) {
                $booted = $device->uptime_at->copy()->subSeconds($device->uptime_seconds);
                $known = array_filter($seen, fn (Carbon $b) => abs($b->diffInSeconds($booted, false)) <= 300);
                if ($known === []) {
                    $events[] = self::event('reboot-'.$booted->timestamp, 'reboot', 'booted', $booted, 'Last boot', $device->os_version ? "Running {$device->os_version}" : null);
                }
            }
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']) ?: strcmp($a['id'], $b['id']));
        $total = count($events);

        return [
            'data' => array_slice($events, ($page - 1) * $perPage, $perPage),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $page * $perPage < $total],
        ];
    }

    /** One row of device_upgrades, worded for the timeline. Public so the Overview can reuse it. */
    public static function upgradeEvent(DeviceUpgrade $u, ?string $by = null): array
    {
        $status = $u->status;
        $at = $u->finished_at ?? $u->started_at ?? $u->queued_at ?? $u->created_at;
        $took = $u->durationSeconds();
        $took = $took !== null ? ' (took '.self::duration($took).')' : '';

        $title = match ($status) {
            UpgradeStatus::Done => ($u->from_version !== null && $u->from_version !== $u->to_version
                ? "Upgraded {$u->from_version} -> ".($u->to_version ?? '?')
                : 'Upgraded to '.($u->to_version ?? 'the latest release')).$took,
            UpgradeStatus::Failed => 'Upgrade failed: '.rtrim($u->message ?? 'no reason recorded', '.'),
            UpgradeStatus::UpToDate => 'Upgrade check: already up to date'.($u->from_version !== null ? " on {$u->from_version}" : ''),
            default => 'Upgrade in progress: '.str_replace('_', ' ', $status->value),
        };

        $bits = [];
        if ($status === UpgradeStatus::Failed && ($u->from_version !== null || $u->to_version !== null)) {
            $bits[] = ($u->from_version ?? '?').' -> '.($u->to_version ?? 'latest').$took;
        } elseif ($status->inProgress() && $u->message !== null) {
            $bits[] = $u->message;
        }
        if ($by !== null) {
            $bits[] = "by {$by}";
        }
        if ($u->batch_id !== null) {
            $bits[] = 'part of a bulk upgrade';
        }

        return self::event("upgrade-{$u->id}", 'upgrade', $status->value, $at, $title, $bits === [] ? null : implode(', ', $bits), [
            'from_version' => $u->from_version,
            'to_version' => $u->to_version,
            'duration_s' => $u->durationSeconds(),
            'batch_id' => $u->batch_id,
            'triggered_by' => $by,
        ]);
    }

    private static function event(string $id, string $type, string $kind, Carbon $at, string $title, ?string $detail, array $extra = []): array
    {
        return ['id' => $id, 'type' => $type, 'kind' => $kind, 'at' => $at->copy()->utc()->toIso8601ZuluString(), 'title' => $title, 'detail' => $detail, ...$extra];
    }

    private static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function duration(int $s): string
    {
        return match (true) {
            $s >= 86400 => intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h',
            $s >= 3600 => intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m',
            $s >= 60 => intdiv($s, 60).'m '.($s % 60).'s',
            default => "{$s}s",
        };
    }
}
