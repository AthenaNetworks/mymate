<?php

namespace Database\Factories;

use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
use App\Models\DiscoveryCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscoveryCandidate> */
class DiscoveryCandidateFactory extends Factory
{
    protected $model = DiscoveryCandidate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip' => fake()->unique()->ipv4(),
            'status' => DiscoveryStatus::New,
            'sysname' => fake()->domainWord(),
            'detected_method' => fake()->randomElement(PollMethod::cases()),
            'matched_credential_id' => null,
            'first_seen' => now(),
            'last_seen' => now(),
        ];
    }
}
