<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Graph;
use App\Models\NetworkInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GraphApiTest extends TestCase
{
    use RefreshDatabase;

    private function iface(): NetworkInterface
    {
        return NetworkInterface::factory()->create(['device_id' => Device::factory()]);
    }

    private function sample(int $interfaceId, string $ts, float $in, float $out): void
    {
        DB::table('interface_samples')->insert([
            'interface_id' => $interfaceId, 'ts' => $ts,
            'bps_in' => $in, 'bps_out' => $out, 'util_in' => null, 'util_out' => null,
        ]);
    }

    public function test_admin_crud_a_graph(): void
    {
        $this->actingAsUser();
        $if = $this->iface();

        $id = $this->postJson('/api/graphs', [
            'name' => 'Uplinks',
            'config' => ['metric' => 'rate', 'show_total' => true, 'series' => [['interface_id' => $if->id, 'direction' => 'in']]],
        ])->assertCreated()->assertJsonPath('data.name', 'Uplinks')->json('data.id');

        $this->getJson('/api/graphs')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/graphs/{$id}", ['name' => 'Uplinks total'])->assertOk()->assertJsonPath('data.name', 'Uplinks total');
        $this->deleteJson("/api/graphs/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('graphs', ['id' => $id]);
    }

    public function test_validation_rejects_a_bad_series(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/graphs', ['name' => 'x', 'config' => ['series' => [['interface_id' => 999999, 'direction' => 'in']]]])
            ->assertStatus(422)->assertJsonValidationErrors('config.series.0.interface_id');

        $if = $this->iface();
        $this->postJson('/api/graphs', ['name' => 'x', 'config' => ['series' => [['interface_id' => $if->id, 'direction' => 'sideways']]]])
            ->assertStatus(422)->assertJsonValidationErrors('config.series.0.direction');
    }

    public function test_data_returns_aligned_series_and_a_summed_total(): void
    {
        $this->actingAsUser();
        $a = $this->iface();
        $b = $this->iface();

        // Two samples for each interface, a few minutes apart (well inside the 24h range).
        $t1 = now()->subMinutes(20)->format('Y-m-d H:i:s');
        $t2 = now()->subMinutes(10)->format('Y-m-d H:i:s');
        $this->sample($a->id, $t1, 100, 10);
        $this->sample($a->id, $t2, 200, 20);
        $this->sample($b->id, $t1, 300, 30);
        $this->sample($b->id, $t2, 400, 40);

        $graph = Graph::factory()->create(['config' => [
            'metric' => 'rate', 'show_total' => true,
            'series' => [['interface_id' => $a->id, 'direction' => 'in'], ['interface_id' => $b->id, 'direction' => 'in']],
        ]]);

        $data = $this->getJson("/api/graphs/{$graph->id}/data?range=24h")->assertOk()->json('data');

        $this->assertSame('rate', $data['metric']);
        $this->assertCount(2, $data['series']);

        // Sum the non-null values of each returned series - a inbound totals 300, b totals 700.
        $sumA = array_sum(array_filter($data['series'][0]['values'], fn ($v) => $v !== null));
        $sumB = array_sum(array_filter($data['series'][1]['values'], fn ($v) => $v !== null));
        $this->assertEquals(300, $sumA);
        $this->assertEquals(700, $sumB);

        // Total is the elementwise sum: its non-null entries add up to a + b = 1000.
        $sumTotal = array_sum(array_filter($data['total'], fn ($v) => $v !== null));
        $this->assertEquals(1000, $sumTotal);
    }

    public function test_non_admin_cannot_create_a_graph(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($viewer)->postJson('/api/graphs', ['name' => 'x', 'config' => ['series' => []]])->assertForbidden();
    }
}
