<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Outage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Outage> */
class OutageFactory extends Factory
{
    protected $model = Outage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'started_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'ended_at' => null, // open by default
            'duration_s' => null,
            'cause' => 'unreachable',
        ];
    }

    /** A resolved (closed) outage with a duration. */
    public function closed(): static
    {
        return $this->state(function (array $attrs): array {
            $started = Carbon::parse($attrs['started_at'] ?? now()->subHour());
            $ended = $started->copy()->addMinutes(fake()->numberBetween(1, 120));

            return ['ended_at' => $ended, 'duration_s' => $started->diffInSeconds($ended)];
        });
    }
}
