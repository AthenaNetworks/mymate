<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\NetworkInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NetworkInterface> */
class NetworkInterfaceFactory extends Factory
{
    protected $model = NetworkInterface::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'if_index' => fake()->unique()->numberBetween(1, 9999),
            'name' => fake()->randomElement(['ether', 'sfp-sfpplus', 'bridge']).fake()->numberBetween(1, 24),
            'speed_mbps' => 1000,
            'last_in' => null,
            'last_out' => null,
            'last_ts' => null,
            'util_in' => null,
            'util_out' => null,
        ];
    }
}
