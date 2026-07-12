<?php

namespace Database\Factories;

use App\Enums\AlertCondition;
use App\Models\AlertPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertPolicy> */
class AlertPolicyFactory extends Factory
{
    protected $model = AlertPolicy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'condition' => AlertCondition::DeviceDown,
            'params' => [],
            'enabled' => true,
        ];
    }
}
