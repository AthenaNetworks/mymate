<?php

namespace Database\Factories;

use App\Models\Sensor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sensor> */
class SensorFactory extends Factory
{
    protected $model = Sensor::class;

    public function definition(): array
    {
        return [
            'name' => 'Custom sensor',
            'oid' => '.1.3.6.1.2.1.1.3.0', // sysUpTime - a harmless default for tests
            'unit' => null,
            'divisor' => 1,
            'scope' => null,
            'enabled' => true,
        ];
    }
}
