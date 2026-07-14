<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/system-status')->assertUnauthorized();
    }

    public function test_it_returns_a_health_board(): void
    {
        $this->actingAsUser();

        $res = $this->getJson('/api/system-status')->assertOk();

        $keys = collect($res->json('data'))->pluck('key')->all();
        // The full set of probes the panel renders.
        foreach (['database', 'redis', 'workers', 'polling', 'websockets', 'backups'] as $expected) {
            $this->assertContains($expected, $keys, "missing status check: {$expected}");
        }

        // Every row is well-formed and carries a known status level.
        foreach ($res->json('data') as $row) {
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('detail', $row);
            $this->assertContains($row['status'], ['ok', 'warn', 'down']);
        }
    }
}
