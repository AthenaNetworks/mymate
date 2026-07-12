<?php

namespace Database\Factories;

use App\Enums\TransportType;
use App\Models\AlertTransport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertTransport> */
class AlertTransportFactory extends Factory
{
    protected $model = AlertTransport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => TransportType::Slack,
            'config' => ['webhook_url' => 'https://hooks.example.com/'.fake()->uuid()],
            'enabled' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn (): array => [
            'type' => TransportType::Email,
            'config' => ['email' => fake()->safeEmail()],
        ]);
    }
}
