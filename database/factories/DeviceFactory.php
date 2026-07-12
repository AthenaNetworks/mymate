<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'mgmt_ip' => fake()->ipv4(),
            'poll_method' => fake()->randomElement(PollMethod::cases()),
            'credential_id' => null,
            'status' => DeviceStatus::Unknown,
            'map_x' => 0,
            'map_y' => 0,
        ];
    }
}
