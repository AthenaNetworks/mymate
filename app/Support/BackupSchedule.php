<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Operator-configurable schedule for automatic config backups. Stored in one `settings`
 * row; the scheduler calls `mymate:backup:run --scheduled` hourly and this decides whether
 * a run is due, so the cadence changes take effect without restarting the scheduler.
 *
 * Defaults to daily at 02:00 (the previous hard-coded behaviour) when nothing is stored.
 */
class BackupSchedule
{
    private const KEY = 'backup.schedule';

    /** @var list<string> */
    public const FREQUENCIES = ['hourly', 'every_6h', 'every_12h', 'daily', 'weekly'];

    /** @return array{enabled:bool, frequency:string, hour:int, weekday:int, last_run_at:?string} */
    public function get(): array
    {
        $c = Setting::where('key', self::KEY)->first()?->value ?? [];

        return [
            'enabled' => (bool) ($c['enabled'] ?? true),
            'frequency' => in_array($c['frequency'] ?? null, self::FREQUENCIES, true) ? $c['frequency'] : 'daily',
            'hour' => (int) ($c['hour'] ?? 2),
            'weekday' => (int) ($c['weekday'] ?? 0), // 0 = Sunday
            'last_run_at' => $c['last_run_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $current = $this->get();
        Setting::updateOrCreate(['key' => self::KEY], [
            'type' => 'backup',
            'value' => [
                'enabled' => (bool) ($input['enabled'] ?? $current['enabled']),
                'frequency' => in_array($input['frequency'] ?? null, self::FREQUENCIES, true) ? $input['frequency'] : $current['frequency'],
                'hour' => max(0, min(23, (int) ($input['hour'] ?? $current['hour']))),
                'weekday' => max(0, min(6, (int) ($input['weekday'] ?? $current['weekday']))),
                'last_run_at' => $current['last_run_at'], // preserved across edits
            ],
        ]);
    }

    /** Whether a scheduled run should fire at $now (the command is invoked hourly). */
    public function due(Carbon $now): bool
    {
        $c = $this->get();
        if (! $c['enabled']) {
            return false;
        }
        $last = $c['last_run_at'] ? Carbon::parse($c['last_run_at']) : null;

        return match ($c['frequency']) {
            'hourly' => $last === null || $last->lt($now->copy()->startOfHour()),
            'every_6h' => $last === null || $last->lte($now->copy()->subHours(6)),
            'every_12h' => $last === null || $last->lte($now->copy()->subHours(12)),
            'weekly' => $now->dayOfWeek === $c['weekday'] && $now->hour === $c['hour']
                && ($last === null || $last->lt($now->copy()->startOfWeek())),
            default /* daily */ => $now->hour === $c['hour'] && ($last === null || $last->lt($now->copy()->startOfDay())),
        };
    }

    /** Record that a scheduled run fired, so `due()` won't fire again this period. */
    public function markRan(Carbon $now): void
    {
        $row = Setting::firstOrNew(['key' => self::KEY]);
        $value = $row->value ?? [];
        $value['last_run_at'] = $now->toIso8601String();
        $row->type = 'backup';
        $row->value = $value;
        $row->save();
    }
}
