<?php

namespace Database\Factories;

use App\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Credential> */
class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'type' => 'snmp',
            'snmp_community' => fake()->word(),
            'username' => null,
            'password' => null,
            'api_port' => 8728,
        ];
    }

    /** A RouterOS credential (user/pass) rather than an SNMP community. */
    public function routeros(): static
    {
        return $this->state(fn () => [
            'type' => 'routeros',
            'snmp_community' => null,
            'username' => fake()->userName(),
            'password' => fake()->password(),
        ]);
    }

    /** An SSH credential (user/pass) for config backups. */
    public function ssh(): static
    {
        return $this->state(fn () => [
            'type' => 'ssh',
            'snmp_community' => null,
            'username' => fake()->userName(),
            'password' => fake()->password(),
        ]);
    }
}
