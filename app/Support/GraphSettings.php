<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The house default look for custom graphs - area fill and stacking. Stored in a single `settings`
 * row (key {@see self::KEY}) so admins can change it in-app, falling back to the config defaults
 * (`mymate.graphs.style`) when unset.
 *
 * This is only the default: each graph may override fill/stacking in its own `config.style`, and a
 * graph with no `config.style` inherits whatever this returns. The palette is assigned to series in
 * order and wraps when there are more series than colours; a series can still pin its own colour
 * (config.series.*.color).
 */
class GraphSettings
{
    private const KEY = 'graph.style';

    /** @return array{fill:bool,stacked:bool,color_mode:string,palette:list<string>} */
    public function style(): array
    {
        $saved = Setting::where('key', self::KEY)->first()?->value ?? [];
        $default = config('mymate.graphs.style', []);

        $palette = $this->palette($saved['palette'] ?? null);
        if ($palette === []) {
            $palette = $this->palette($default['palette'] ?? []);
        }

        return [
            'fill' => (bool) ($saved['fill'] ?? $default['fill'] ?? false),
            'stacked' => (bool) ($saved['stacked'] ?? $default['stacked'] ?? false),
            'color_mode' => $this->colorMode($saved['color_mode'] ?? $default['color_mode'] ?? null),
            'palette' => $palette,
        ];
    }

    /**
     * @param  array{fill?:bool,stacked?:bool,color_mode?:string,palette?:array<mixed>}  $style
     * @return array{fill:bool,stacked:bool,color_mode:string,palette:list<string>}
     */
    public function setStyle(array $style): array
    {
        // Never persist an empty palette - fall back to the config default so graphs always draw.
        $palette = $this->palette($style['palette'] ?? []);
        if ($palette === []) {
            $palette = $this->palette(config('mymate.graphs.style.palette', []));
        }

        $value = [
            'fill' => (bool) ($style['fill'] ?? false),
            'stacked' => (bool) ($style['stacked'] ?? false),
            'color_mode' => $this->colorMode($style['color_mode'] ?? null),
            'palette' => $palette,
        ];

        Setting::updateOrCreate(['key' => self::KEY], ['value' => $value, 'type' => 'json']);

        return $value;
    }

    /** Only 'group' or 'series'; anything else falls back to the shared-per-interface default. */
    private function colorMode(mixed $raw): string
    {
        return $raw === 'series' ? 'series' : 'group';
    }

    /**
     * Keep only well-formed #rrggbb (lowercased, order preserved, deduped), capped at 32 so a
     * runaway list can't bloat the row.
     *
     * @return list<string>
     */
    private function palette(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $c) {
            if (is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
                $out[strtolower($c)] = true; // key-dedupe, preserves first-seen order
            }
        }

        return array_slice(array_keys($out), 0, 32);
    }
}
