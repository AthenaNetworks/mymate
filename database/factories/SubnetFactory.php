<?php

namespace Database\Factories;

use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subnet> */
class SubnetFactory extends Factory
{
    protected $model = Subnet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cidr' => '10.'.fake()->unique()->numberBetween(0, 255).'.0.0/16',
            'label' => fake()->words(2, true),
            'enabled' => true,
            'scan_interval_s' => 3600,
            'last_scanned_at' => null,
        ];
    }
}
