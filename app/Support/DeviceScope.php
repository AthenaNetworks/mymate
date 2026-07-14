<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceMapPosition;

/**
 * Resolve a targeting bag (the `scope` shape shared by alert policies and maintenance
 * windows) to the set of device ids it covers, or `null` for fleet-wide. An explicit
 * scope that matches nothing returns an empty array (covers no devices).
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

        return match ($scope['type'] ?? 'all') {
            'device_type' => Device::where('device_type', $scope['device_type'] ?? '')->pluck('id')->all(),
            'map' => DeviceMapPosition::where('map_id', (int) ($scope['map_id'] ?? 0))->pluck('device_id')->unique()->values()->all(),
            'devices' => array_values(array_map('intval', $scope['device_ids'] ?? [])),
            default => null, // 'all'
        };
    }
}
