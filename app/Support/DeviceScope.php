<?php

namespace App\Support;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use Illuminate\Http\Request;

/**
 * Resolve a targeting bag (the `scope` shape shared by alert policies, maintenance windows and
 * custom sensors) to the set of device ids it covers, or `null` for fleet-wide. An explicit scope
 * that matches nothing returns an empty array (covers no devices).
 *
 * Two shapes are accepted:
 *
 *  - The original single choice, still read as before: `{type: all}`, `{type: device_type,
 *    device_type}`, `{type: map, map_id}` (devices placed directly on that map only) and
 *    `{type: devices, device_ids}`.
 *
 *  - Combinations with exclusions:
 *
 *        {include: {device_types[], map_ids[], submaps, device_ids[]},
 *         exclude: {device_types[], map_ids[], submaps, device_ids[]}}
 *
 *    Include: within a field any value matches (OR); across device_types and map_ids a device
 *    must match each field that's set (AND) - "routers or switches on map 4 or 9". device_ids are
 *    added on top whatever the rules say. Nothing set means every device. Exclude: a device that
 *    matches ANY exclude field (a type, a map, or listed) is removed, and exclusions always win.
 *    `submaps` (off by default) widens that side's maps to every map under them.
 *
 * Device visibility scopes (restricted operators) are ignored: targeting is an admin setting
 * applied by the engine, and it must resolve the same however it's asked.
 */
class DeviceScope
{
    /**
     * @param  array<string, mixed>|null  $scope
     * @return array<int>|null null = all devices
     */
    public static function resolve(?array $scope): ?array
    {
        $scope ??= [];
        if (self::isCombination($scope)) {
            return self::resolveCombination($scope);
        }

        return match ($scope['type'] ?? 'all') {
            'device_type' => Device::withoutGlobalScopes()->where('device_type', $scope['device_type'] ?? '')->pluck('id')->all(),
            'map' => DeviceMapPosition::where('map_id', (int) ($scope['map_id'] ?? 0))->pluck('device_id')->unique()->values()->all(),
            'devices' => array_values(array_map('intval', $scope['device_ids'] ?? [])),
            default => null, // 'all'
        };
    }

    /**
     * A clean copy to store: only the known fields, ids as unique ints, types as known values.
     * A scope that targets everything with no exclusions is stored as null (fleet-wide), as
     * before; the original single-choice shape is kept as it is.
     *
     * @param  array<string, mixed>|null  $scope
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $scope): ?array
    {
        if ($scope === null || $scope === []) {
            return null;
        }
        if (! self::isCombination($scope)) {
            $type = (string) ($scope['type'] ?? 'all');

            return match ($type) {
                'device_type' => ['type' => 'device_type', 'device_type' => (string) ($scope['device_type'] ?? '')],
                'map' => ['type' => 'map', 'map_id' => (int) ($scope['map_id'] ?? 0)],
                'devices' => ['type' => 'devices', 'device_ids' => self::ints($scope['device_ids'] ?? [])],
                default => null,
            };
        }

        $out = ['include' => self::side($scope['include'] ?? []), 'exclude' => self::side($scope['exclude'] ?? [])];

        return self::isEmptySide($out['include']) && self::isEmptySide($out['exclude']) ? null : $out;
    }

    /**
     * The scope a (validated) request sent, or null. Read from the input rather than validated():
     * Laravel's validated() drops an array whose nested rules matched nothing - so a scope cleared
     * to `{include: [], exclude: []}` would vanish and the old one stay. Store it through
     * normalize(), which keeps only the known fields.
     *
     * @return array<string, mixed>|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $scope = $request->input('scope');

        return is_array($scope) ? $scope : null;
    }

    /** True for the include/exclude shape (rather than the original `type` one). */
    public static function isCombination(array $scope): bool
    {
        return array_key_exists('include', $scope) || array_key_exists('exclude', $scope);
    }

    /** @return array<int>|null */
    private static function resolveCombination(array $scope): ?array
    {
        $inc = self::side($scope['include'] ?? []);
        $exc = self::side($scope['exclude'] ?? []);

        // What the include rules match: null = every device (no type or map rule).
        $matched = null;
        if ($inc['device_types'] !== [] || $inc['map_ids'] !== []) {
            $q = Device::withoutGlobalScopes();
            if ($inc['device_types'] !== []) {
                $q->whereIn('device_type', $inc['device_types']);
            }
            if ($inc['map_ids'] !== []) {
                $q->whereIn('id', DeviceMapPosition::query()->select('device_id')
                    ->whereIn('map_id', self::maps($inc['map_ids'], $inc['submaps'])));
            }
            $matched = $q->pluck('id')->all();
        } elseif ($inc['device_ids'] !== []) {
            $matched = []; // only the listed devices
        }

        $excluded = self::excluded($exc);

        if ($matched === null && $excluded === []) {
            return null; // fleet-wide, nothing taken out
        }
        $matched ??= Device::withoutGlobalScopes()->pluck('id')->all();
        $ids = array_values(array_unique([...array_map('intval', $matched), ...$inc['device_ids']]));
        if ($excluded !== []) {
            $drop = array_flip($excluded);
            $ids = array_values(array_filter($ids, static fn (int $id): bool => ! isset($drop[$id])));
        }
        sort($ids);

        return $ids;
    }

    /**
     * Every device an exclude side removes: any listed type, anything on a listed map (and its
     * sub-maps when asked), and the listed devices.
     *
     * @param  array{device_types: list<string>, map_ids: list<int>, submaps: bool, device_ids: list<int>}  $exc
     * @return array<int>
     */
    private static function excluded(array $exc): array
    {
        $ids = $exc['device_ids'];
        if ($exc['device_types'] !== []) {
            $ids = [...$ids, ...Device::withoutGlobalScopes()->whereIn('device_type', $exc['device_types'])->pluck('id')->all()];
        }
        if ($exc['map_ids'] !== []) {
            $ids = [...$ids, ...DeviceMapPosition::whereIn('map_id', self::maps($exc['map_ids'], $exc['submaps']))->pluck('device_id')->all()];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The maps a rule covers: the listed ones, plus every map under them when `submaps` is on.
     *
     * @param  list<int>  $mapIds
     * @return list<int>
     */
    private static function maps(array $mapIds, bool $submaps): array
    {
        if (! $submaps) {
            return $mapIds;
        }

        $childrenOf = [];
        foreach (Map::withoutGlobalScopes()->get(['id', 'parent_map_id']) as $m) {
            $childrenOf[(int) $m->parent_map_id][] = (int) $m->id;
        }
        $out = [];
        $stack = $mapIds;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($out[$id])) {
                continue; // a cycle, or a map listed twice
            }
            $out[$id] = true;
            foreach ($childrenOf[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($out);
    }

    /**
     * One side, cleaned: known device types only, positive unique int ids, a real boolean.
     *
     * @return array{device_types: list<string>, map_ids: list<int>, submaps: bool, device_ids: list<int>}
     */
    private static function side(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $known = array_map(static fn (DeviceType $t): string => $t->value, DeviceType::cases());
        $types = array_values(array_unique(array_filter(
            array_map('strval', array_filter((array) ($raw['device_types'] ?? []), 'is_scalar')),
            static fn (string $t): bool => in_array($t, $known, true),
        )));
        sort($types);

        return [
            'device_types' => $types,
            'map_ids' => self::ints($raw['map_ids'] ?? []),
            'submaps' => filter_var($raw['submaps'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'device_ids' => self::ints($raw['device_ids'] ?? []),
        ];
    }

    private static function isEmptySide(array $side): bool
    {
        return $side['device_types'] === [] && $side['map_ids'] === [] && $side['device_ids'] === [];
    }

    /** @return list<int> */
    private static function ints(mixed $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter((array) $values, 'is_numeric')),
            static fn (int $v): bool => $v > 0,
        )));
        sort($ids);

        return $ids;
    }
}
