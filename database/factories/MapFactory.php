<?php

namespace Database\Factories;

use App\Models\Map;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Map> */
class MapFactory extends Factory
{
    protected $model = Map::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'parent_map_id' => null,
            'is_default' => false,
            'position' => 0,
        ];
    }
}
