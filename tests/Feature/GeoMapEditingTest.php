<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\MapNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GitHub #52: labels on the geo map - a note can carry a real-world position. */
class GeoMapEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_note_can_be_placed_and_moved_on_the_geo_map(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create(['leaflet_enabled' => true]);

        $id = $this->postJson("/api/maps/{$map->id}/notes", ['text' => 'Hilltop', 'latitude' => -27.4698, 'longitude' => 153.0251])
            ->assertCreated()
            ->assertJsonPath('data.latitude', -27.4698)
            ->json('data.id');

        $this->patchJson("/api/maps/{$map->id}/notes/{$id}", ['latitude' => -27.5, 'longitude' => 153.1])->assertOk()
            ->assertJsonPath('data.longitude', 153.1);
        $this->getJson("/api/maps/{$map->id}")->assertJsonPath('data.map_notes.0.latitude', -27.5);

        // Half a coordinate, or one off the globe, is refused.
        $this->patchJson("/api/maps/{$map->id}/notes/{$id}", ['latitude' => 10])->assertUnprocessable();
        $this->patchJson("/api/maps/{$map->id}/notes/{$id}", ['latitude' => 91, 'longitude' => 0])->assertUnprocessable();
    }

    public function test_a_plain_note_stays_off_the_geo_map(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $this->postJson("/api/maps/{$map->id}/notes", ['text' => 'Rack 3', 'x' => 10, 'y' => 20])->assertCreated()
            ->assertJsonPath('data.latitude', null);
        $this->assertNull(MapNote::firstOrFail()->longitude);
    }

    public function test_a_logical_wallboard_share_never_hands_out_geo_labels(): void
    {
        $map = Map::factory()->create(['leaflet_enabled' => true]);
        MapNote::create(['map_id' => $map->id, 'text' => 'Hilltop', 'latitude' => -27.4698, 'longitude' => 153.0251]);
        MapNote::create(['map_id' => $map->id, 'text' => 'Rack 3', 'x' => 1, 'y' => 2]);
        $logical = \App\Models\MapShare::create(['map_id' => $map->id, 'token' => \App\Models\MapShare::newToken(), 'enabled' => true]);
        $geo = \App\Models\MapShare::create(['map_id' => $map->id, 'token' => \App\Models\MapShare::newToken(), 'enabled' => true, 'view' => 'geo']);

        $res = $this->getJson("/api/public/wall/{$logical->token}/map")->assertOk();
        $this->assertSame(['Rack 3'], array_column($res->json('data.map_notes'), 'text'));
        $this->assertStringNotContainsString('153.0251', $res->getContent());
        $this->assertStringNotContainsString('latitude', $res->getContent());

        $this->getJson("/api/public/wall/{$geo->token}/map")->assertOk()->assertJsonCount(2, 'data.map_notes');
    }
}
