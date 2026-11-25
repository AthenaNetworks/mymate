<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Validation\Rule;

/**
 * Validation for a targeting `scope` (alert policies, maintenance windows, custom sensors), in
 * either shape App\Support\DeviceScope reads: the original single choice (`type` + its field), or
 * combinations with exclusions (`include` / `exclude`). The two can't be mixed in one scope.
 * Shared so the three features can't drift apart.
 */
final class ScopeRules
{
    /** Big enough for any real selection, small enough that a request can't be a denial of service. */
    private const MAX_DEVICES = 5000;

    private const MAX_MAPS = 1000;

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $rules = [
            'scope' => ['nullable', 'array'],
            // The original single-choice shape.
            'scope.type' => ['nullable', Rule::in(['all', 'device_type', 'map', 'devices']), 'prohibits:scope.include,scope.exclude'],
            'scope.device_type' => ['nullable', 'required_if:scope.type,device_type', Rule::enum(DeviceType::class)],
            'scope.map_id' => ['nullable', 'required_if:scope.type,map', 'integer', 'exists:maps,id'],
            'scope.device_ids' => ['nullable', 'required_if:scope.type,devices', 'array', 'max:'.self::MAX_DEVICES],
            'scope.device_ids.*' => ['integer', 'exists:devices,id'],
        ];

        // Combinations: the same fields on each side.
        foreach (['include', 'exclude'] as $side) {
            $rules += [
                "scope.{$side}" => ['sometimes', 'array'],
                "scope.{$side}.device_types" => ['sometimes', 'array', 'max:'.count(DeviceType::cases())],
                "scope.{$side}.device_types.*" => ['distinct', Rule::enum(DeviceType::class)],
                "scope.{$side}.map_ids" => ['sometimes', 'array', 'max:'.self::MAX_MAPS],
                "scope.{$side}.map_ids.*" => ['integer', 'distinct', 'exists:maps,id'],
                "scope.{$side}.submaps" => ['sometimes', 'boolean'],
                "scope.{$side}.device_ids" => ['sometimes', 'array', 'max:'.self::MAX_DEVICES],
                "scope.{$side}.device_ids.*" => ['integer', 'distinct', 'exists:devices,id'],
            ];
        }

        return $rules;
    }
}
