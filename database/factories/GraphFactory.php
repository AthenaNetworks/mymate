<?php

namespace Database\Factories;

use App\Models\Graph;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Graph> */
class GraphFactory extends Factory
{
    protected $model = Graph::class;

    public function definition(): array
    {
        return [
            'name' => 'Internet usage',
            'config' => ['metric' => 'rate', 'series' => [], 'show_total' => true],
        ];
    }
}
