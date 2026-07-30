<?php

namespace Database\Factories;

use App\Enums\SiteKind;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Site> */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->streetName().' Tower',
            'kind' => SiteKind::Tower,
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'external_ref' => fake()->unique()->uuid(),
        ];
    }

    /** A site with no coordinates yet (can't place devices). */
    public function unplaced(): static
    {
        return $this->state(fn () => ['latitude' => null, 'longitude' => null]);
    }

    /** Pin the site at exact coordinates (nearest-site tests need known distances). */
    public function at(float $lat, float $lng): static
    {
        return $this->state(fn () => ['latitude' => $lat, 'longitude' => $lng]);
    }
}
