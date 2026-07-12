<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Agent> */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' agent',
            'token_hash' => Agent::hashToken('mma_'.Str::random(48)),
            'status' => AgentStatus::Enrolled,
        ];
    }
}
