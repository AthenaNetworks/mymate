<?php

namespace App\Support;

use App\Models\Device;

/**
 * What a set of saved targeting scopes cover, for list views (the Alerts page's policy list):
 * how many devices each one matches, a few of their names, and the names of the devices a scope
 * lists one by one (included or excepted), so the list can show "edge-gw-01" rather than
 * "1 device". Resolved with DeviceScope, exactly as the engine does.
 *
 * One names query covers every listed device across all the scopes; each scope then costs one
 * resolve plus one small sample query.
 */
final class ScopeCoverage
{
    /** Example names per scope (the list shows them on hover). */
    public const SAMPLE = 8;

    /**
     * @param  array<int|string, array<string, mixed>|null>  $scopes  keyed however the caller likes
     * @return array<int|string, array{matched: int, total: int, sample: list<string>, device_names: array<int, string>}> same keys
     */
    public static function forScopes(array $scopes): array
    {
        if ($scopes === []) {
            return [];
        }

        $total = Device::withoutGlobalScopes()->count();

        $listed = [];
        foreach ($scopes as $key => $scope) {
            $listed[$key] = self::listedDeviceIds($scope);
        }
        $allListed = array_values(array_unique(array_merge(...array_values($listed))));
        $names = $allListed === []
            ? []
            : Device::withoutGlobalScopes()->whereIn('id', $allListed)->pluck('name', 'id')->all();

        $fleetSample = null; // every fleet-wide scope shares one sample
        $out = [];
        foreach ($scopes as $key => $scope) {
            $ids = DeviceScope::resolve($scope);
            if ($ids === null) {
                $fleetSample ??= Device::withoutGlobalScopes()->orderBy('name')->limit(self::SAMPLE)->pluck('name')->all();
                $sample = $fleetSample;
            } else {
                $sample = $ids === []
                    ? []
                    : Device::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->limit(self::SAMPLE)->pluck('name')->all();
            }

            $out[$key] = [
                'matched' => $ids === null ? $total : count($ids),
                'total' => $total,
                'sample' => array_values($sample),
                // A listed device that's since been deleted has no name; the list says so.
                'device_names' => array_intersect_key($names, array_flip($listed[$key])),
            ];
        }

        return $out;
    }

    /**
     * Every device a scope names one by one, on either side.
     *
     * @param  array<string, mixed>|null  $scope
     * @return list<int>
     */
    private static function listedDeviceIds(?array $scope): array
    {
        $scope = DeviceScope::normalize($scope);
        if ($scope === null) {
            return [];
        }
        if (DeviceScope::isCombination($scope)) {
            return array_values(array_unique(array_merge($scope['include']['device_ids'], $scope['exclude']['device_ids'])));
        }

        return ($scope['type'] ?? null) === 'devices' ? $scope['device_ids'] : [];
    }
}
