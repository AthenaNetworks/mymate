<?php

namespace Database\Factories;

use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceWindow> */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        return [
            'name' => 'Planned work',
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHours(2),
            'scope' => null, // fleet-wide
            'enabled' => true,
        ];
    }

    /** A window that is not yet in effect. */
    public function upcoming(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
    }

    /** A window that has already ended. */
    public function past(): static
    {
        return $this->state(fn (): array => ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
    }
}
