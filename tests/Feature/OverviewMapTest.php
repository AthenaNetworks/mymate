<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub #9: child-map nodes placed on an overview map + manual device-less links between
 * them (styled by media type).
 */
class OverviewMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_place_move_and_remove_a_child_map_node(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $child = Map::create(['name' => 'Region A']);

        $this->postJson("/api/maps/{$canvas->id}/child-maps", ['child_map_id' => $child->id, 'x' => 100, 'y' => 60])
            ->assertCreated();
        $this->assertDatabaseHas('maps', ['id' => $child->id, 'parent_map_id' => $canvas->id, 'node_x' => 100, 'node_y' => 60]);

        // It comes back in the canvas payload as a child-map node.
        $this->getJson("/api/maps/{$canvas->id}")
            ->assertOk()
            ->assertJsonPath('data.child_maps.0.id', $child->id)
            ->assertJsonPath('data.child_maps.0.node_x', 100);

        $this->patchJson("/api/maps/{$canvas->id}/child-maps/{$child->id}/position", ['x' => 200, 'y' => 90])
            ->assertOk();
        $this->assertDatabaseHas('maps', ['id' => $child->id, 'node_x' => 200, 'node_y' => 90]);

        $this->deleteJson("/api/maps/{$canvas->id}/child-maps/{$child->id}")->assertNoContent();
        $this->assertDatabaseHas('maps', ['id' => $child->id, 'parent_map_id' => null, 'node_x' => null]);
    }

    public function test_detaching_a_child_removes_only_the_links_that_touch_it_on_this_canvas(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $a = Map::create(['name' => 'A', 'parent_map_id' => $canvas->id]);
        $b = Map::create(['name' => 'B', 'parent_map_id' => $canvas->id]);
        $c = Map::create(['name' => 'C', 'parent_map_id' => $canvas->id]);
        $touches = MapLink::create(['map_id' => $canvas->id, 'a_map_id' => $a->id, 'b_map_id' => $b->id]);
        $keep = MapLink::create(['map_id' => $canvas->id, 'a_map_id' => $b->id, 'b_map_id' => $c->id]);
        // A link on a different canvas that also references A must survive.
        $other = Map::create(['name' => 'Other']);
        $oa = Map::create(['name' => 'OA', 'parent_map_id' => $other->id]);
        $elsewhere = MapLink::create(['map_id' => $other->id, 'a_map_id' => $oa->id, 'b_map_id' => $a->id]);

        $this->deleteJson("/api/maps/{$canvas->id}/child-maps/{$a->id}")->assertNoContent();

        $this->assertDatabaseMissing('map_links', ['id' => $touches->id]);
        $this->assertDatabaseHas('map_links', ['id' => $keep->id]);
        $this->assertDatabaseHas('map_links', ['id' => $elsewhere->id]); // other canvas untouched
    }

    public function test_placing_a_map_on_itself_or_creating_a_cycle_is_rejected(): void
    {
        $a = Map::create(['name' => 'A']);
        $b = Map::create(['name' => 'B', 'parent_map_id' => $a->id]);

        // A can't be a node on itself.
        $this->postJson("/api/maps/{$a->id}/child-maps", ['child_map_id' => $a->id])->assertStatus(422);
        // Placing A (an ancestor) onto B would make a cycle.
        $this->postJson("/api/maps/{$b->id}/child-maps", ['child_map_id' => $a->id])->assertStatus(422);
    }

    public function test_draw_style_and_delete_a_manual_link_between_child_map_nodes(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $a = Map::create(['name' => 'A', 'parent_map_id' => $canvas->id]);
        $b = Map::create(['name' => 'B', 'parent_map_id' => $canvas->id]);

        $id = $this->postJson("/api/maps/{$canvas->id}/map-links", [
            'a_map_id' => $a->id, 'b_map_id' => $b->id, 'media_type' => 'fiber',
            'a_handle' => 's-bottom', 'b_handle' => 't-top',
        ])
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'fiber')
            ->assertJsonPath('data.a_handle', 's-bottom')
            ->json('data.id');

        $this->getJson("/api/maps/{$canvas->id}")->assertOk()->assertJsonPath('data.map_links.0.id', $id);

        $this->patchJson("/api/maps/{$canvas->id}/map-links/{$id}", ['media_type' => 'wireless', 'label' => 'PtP 5GHz'])
            ->assertOk()
            ->assertJsonPath('data.media_type', 'wireless')
            ->assertJsonPath('data.label', 'PtP 5GHz');

        $this->deleteJson("/api/maps/{$canvas->id}/map-links/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('map_links', ['id' => $id]);
    }

    public function test_map_link_ends_must_be_child_nodes_of_the_canvas(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $a = Map::create(['name' => 'A', 'parent_map_id' => $canvas->id]);
        $stray = Map::create(['name' => 'Elsewhere']); // not a child of the canvas

        $this->postJson("/api/maps/{$canvas->id}/map-links", ['a_map_id' => $a->id, 'b_map_id' => $stray->id])
            ->assertJsonValidationErrors('b_map_id');

        // Both ends the same is rejected too.
        $this->postJson("/api/maps/{$canvas->id}/map-links", ['a_map_id' => $a->id, 'b_map_id' => $a->id])
            ->assertJsonValidationErrors('b_map_id');
    }

    public function test_an_invalid_media_type_is_rejected(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $a = Map::create(['name' => 'A', 'parent_map_id' => $canvas->id]);
        $b = Map::create(['name' => 'B', 'parent_map_id' => $canvas->id]);

        $this->postJson("/api/maps/{$canvas->id}/map-links", ['a_map_id' => $a->id, 'b_map_id' => $b->id, 'media_type' => 'carrier-pigeon'])
            ->assertJsonValidationErrors('media_type');
    }

    public function test_deleting_the_canvas_map_removes_its_manual_links(): void
    {
        $canvas = Map::create(['name' => 'Core']);
        $a = Map::create(['name' => 'A', 'parent_map_id' => $canvas->id]);
        $b = Map::create(['name' => 'B', 'parent_map_id' => $canvas->id]);
        $link = MapLink::create(['map_id' => $canvas->id, 'a_map_id' => $a->id, 'b_map_id' => $b->id]);

        $canvas->delete();

        $this->assertDatabaseMissing('map_links', ['id' => $link->id]);
    }
}
