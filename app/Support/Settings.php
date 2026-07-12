<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Engine settings: a thin repository that reads operator overrides
 * from the `settings` table, falling back to `config/mymate.php`. The loop + history
 * maintenance read through this each cycle, so a change applies without a restart.
 *
 * Only the keys in SCHEMA are operator-editable - each is genuinely read live below.
 */
class Settings
{
    /** Editable tunables: config-key => [min, max, label]. Default = config("mymate.$key"). */
    public const SCHEMA = [
        'ping.interval' => ['min' => 1, 'max' => 3600, 'label' => 'Up/down ping interval (seconds)'],
        'poll.interval' => ['min' => 1, 'max' => 3600, 'label' => 'Throughput poll interval (seconds)'],
        'poll.discover_interval' => ['min' => 60, 'max' => 86400, 'label' => 'Interface re-discovery interval (seconds)'],
        'discovery.check_interval' => ['min' => 5, 'max' => 3600, 'label' => 'Discovery scan-check interval (seconds)'],
        'history.retention_days' => ['min' => 1, 'max' => 365, 'label' => 'Recent-history retention (days)'],
    ];

    /** DB override if present, else the config/mymate.php default. */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::where('key', $key)->first();

        return $row !== null ? $row->value : config("mymate.$key", $default);
    }

    public function getInt(string $key, int $default): int
    {
        return (int) $this->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Effective value (override or default) + meta for every editable key - the UI form.
     *
     * @return list<array{key:string, value:int, label:string, min:int, max:int}>
     */
    public function effective(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => $meta) {
            $out[] = [
                'key' => $key,
                'value' => (int) $this->get($key, 0),
                'label' => $meta['label'],
                'min' => $meta['min'],
                'max' => $meta['max'],
            ];
        }

        return $out;
    }
}
