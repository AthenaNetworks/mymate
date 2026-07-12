<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Support\DeviceHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_a_linear_chain_deepest_first(): void
    {
        $a = Device::factory()->create();                                  // root
        $b = Device::factory()->create(['parent_device_id' => $a->id]);    // depth 1
        $c = Device::factory()->create(['parent_device_id' => $b->id]);    // depth 2 (leaf)

        $ordered = (new DeviceHierarchy)->orderDownstreamFirst([$a->id, $b->id, $c->id]);

        $this->assertSame([$c->id, $b->id, $a->id], $ordered);
    }

    public function test_orders_a_branching_tree_by_depth_then_id(): void
    {
        $root = Device::factory()->create();
        $c1 = Device::factory()->create(['parent_device_id' => $root->id]); // depth 1
        $c2 = Device::factory()->create(['parent_device_id' => $root->id]); // depth 1
        $g = Device::factory()->create(['parent_device_id' => $c1->id]);    // depth 2

        $ordered = (new DeviceHierarchy)->orderDownstreamFirst([$root->id, $c1->id, $c2->id, $g->id]);

        // Deepest first; ties (c1, c2) break by id ascending; root last.
        $this->assertSame([$g->id, $c1->id, $c2->id, $root->id], $ordered);
    }

    public function test_a_parent_cycle_does_not_hang(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $a->update(['parent_device_id' => $b->id]);
        $b->update(['parent_device_id' => $a->id]); // a <-> b cycle

        $ordered = (new DeviceHierarchy)->orderDownstreamFirst([$a->id, $b->id]);

        $this->assertCount(2, $ordered);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ordered);
    }

    public function test_dedupes_and_ignores_order_of_input(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create(['parent_device_id' => $a->id]);

        $ordered = (new DeviceHierarchy)->orderDownstreamFirst([$a->id, $b->id, $a->id]);

        $this->assertSame([$b->id, $a->id], $ordered);
    }
}
