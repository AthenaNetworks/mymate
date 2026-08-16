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

    public function test_data_resolves_ping_and_probe_latency_sources(): void
    {
        $this->actingAsUser();
        $device = Device::factory()->create();
        $probe = \App\Models\Probe::factory()->create(['device_id' => $device->id]);
        $t = now()->subMinutes(10)->format('Y-m-d H:i:s');
        DB::table('ping_samples')->insert(['device_id' => $device->id, 'ts' => $t, 'rtt_ms' => 12.5, 'loss_pct' => 0, 'jitter_ms' => 1]);
        DB::table('probe_samples')->insert(['probe_id' => $probe->id, 'ts' => $t, 'up' => true, 'latency_ms' => 45]);

        $graph = Graph::factory()->create(['config' => ['metric' => 'rate', 'show_total' => false, 'series' => [
            ['source' => 'ping', 'device_id' => $device->id],
            ['source' => 'probe', 'probe_id' => $probe->id],
        ]]]);

        $data = $this->getJson("/api/graphs/{$graph->id}/data?range=24h")->assertOk()->json('data');

        $this->assertCount(2, $data['series']);
        $this->assertSame('ms', $data['series'][0]['format']);
        $this->assertSame('ms', $data['series'][1]['format']);
        $this->assertNotEmpty(array_filter($data['series'][0]['values'], fn ($v) => $v !== null));
        $this->assertNotEmpty(array_filter($data['series'][1]['values'], fn ($v) => $v !== null));
    }

    public function test_non_admin_cannot_create_a_graph(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($viewer)->postJson('/api/graphs', ['name' => 'x', 'config' => ['series' => []]])->assertForbidden();
    }

    public function test_a_graph_persists_its_style_and_series_colour(): void
    {
        $this->actingAsUser();
        $if = $this->iface();

        $id = $this->postJson('/api/graphs', [
            'name' => 'Filled',
            'config' => [
                'metric' => 'rate', 'show_total' => false,
                'series' => [['interface_id' => $if->id, 'direction' => 'in', 'color' => '#3987e5', 'name' => 'WAN in']],
                'style' => ['fill' => true, 'stacked' => true, 'color_mode' => 'series'],
            ],
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/graphs')->assertOk()
            ->assertJsonPath('data.0.config.style.stacked', true)
            ->assertJsonPath('data.0.config.style.color_mode', 'series')
            ->assertJsonPath('data.0.config.series.0.color', '#3987e5');

        // The resolved data carries the per-series colour and the custom name (overriding the label).
        $this->getJson("/api/graphs/{$id}/data?range=24h")->assertOk()
            ->assertJsonPath('data.series.0.color', '#3987e5')
            ->assertJsonPath('data.series.0.label', 'WAN in');

        // A malformed colour is rejected rather than silently stored.
        $this->putJson("/api/graphs/{$id}", ['config' => ['series' => [['interface_id' => $if->id, 'direction' => 'in', 'color' => 'blue']]]])
            ->assertStatus(422)->assertJsonValidationErrors('config.series.0.color');
    }

    public function test_graph_style_default_is_readable_and_admin_writable(): void
    {
        $this->actingAsUser();

        // Ships the config default (incl. the categorical palette + colour mode) until set.
        $this->getJson('/api/settings/graph-style')->assertOk()
            ->assertJsonPath('data.fill', false)
            ->assertJsonPath('data.stacked', false)
            ->assertJsonPath('data.color_mode', 'group')
            ->assertJsonPath('data.palette.0', '#3987e5');

        $this->putJson('/api/settings/graph-style', ['fill' => true, 'stacked' => false, 'color_mode' => 'series', 'palette' => ['#112233', '#AABBCC']])
            ->assertOk()->assertJsonPath('data.fill', true)
            ->assertJsonPath('data.color_mode', 'series')
            ->assertJsonPath('data.palette', ['#112233', '#aabbcc']); // lowercased

        $this->getJson('/api/settings/graph-style')->assertOk()->assertJsonPath('data.palette.1', '#aabbcc');

        // A bad colour in the palette, an empty palette, and a bad colour mode are all rejected.
        $this->putJson('/api/settings/graph-style', ['fill' => true, 'stacked' => false, 'color_mode' => 'group', 'palette' => ['#112233', 'red']])
            ->assertStatus(422)->assertJsonValidationErrors('palette.1');
        $this->putJson('/api/settings/graph-style', ['fill' => true, 'stacked' => false, 'color_mode' => 'group', 'palette' => []])
            ->assertStatus(422)->assertJsonValidationErrors('palette');
        $this->putJson('/api/settings/graph-style', ['fill' => true, 'stacked' => false, 'color_mode' => 'rainbow', 'palette' => ['#112233']])
            ->assertStatus(422)->assertJsonValidationErrors('color_mode');
    }

    public function test_non_admin_can_read_but_not_change_graph_default(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        $this->actingAs($viewer)->getJson('/api/settings/graph-style')->assertOk();
        $this->actingAs($viewer)->putJson('/api/settings/graph-style', ['fill' => true, 'stacked' => false, 'color_mode' => 'group', 'palette' => ['#112233']])->assertForbidden();
    }
}
