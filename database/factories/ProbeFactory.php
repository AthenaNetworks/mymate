<?php

namespace Database\Factories;

use App\Enums\ProbeKind;
use App\Models\Device;
use App\Models\Probe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Probe> */
class ProbeFactory extends Factory
{
    protected $model = Probe::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'name' => 'Web check',
            'kind' => ProbeKind::Http,
            'enabled' => true,
            'interval_s' => 60,
            'timeout_ms' => 5000,
            'fail_threshold' => 2,
            'config' => ['url' => 'https://example.test/health', 'expect_status' => '200-399'],
        ];
    }

    public function tcp(int $port = 443): static
    {
        return $this->state(fn () => [
            'kind' => ProbeKind::Tcp,
            'name' => 'Port check',
            'config' => ['port' => $port],
        ]);
    }
}
