<?php

namespace Database\Factories;

use App\Models\RouterosPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RouterosPackage> */
class RouterosPackageFactory extends Factory
{
    protected $model = RouterosPackage::class;

    public function definition(): array
    {
        return [
            'version' => '7.15.3',
            'arch' => 'arm',
            'channel' => 'stable',
            'status' => 'pending',
            'token' => Str::random(40),
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => 'ready',
            'size_bytes' => 11557064,
            'path' => 'routeros-packages/routeros-7.15.3-arm.npk',
            'fetched_at' => now(),
        ]);
    }
}
