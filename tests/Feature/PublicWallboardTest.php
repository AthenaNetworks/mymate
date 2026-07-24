<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\MapShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public wallboard share links (GitHub #15). The security-critical properties: the token gates
 * access, the payload never leaks secrets, disabled/unknown tokens 404, and only admins can mint.
 */
class PublicWallboardTest extends TestCase
{
    use RefreshDatabase;

    private function mapWithDevice(): array
    {
        $map = Map::factory()->create(['name' => 'NOC']);
        $device = Device::factory()->create([
            'name' => 'core1',
            'mgmt_ip' => '10.9.9.9',
        ]);
        DeviceMapPosition::create(['device_id' => $device->id, 'map_id' => $map->id, 'x' => 12, 'y' => 34]);

        return [$map, $device];
    }

    public function test_admin_can_mint_and_a_valid_token_serves_the_map(): void
    {
        $this->actingAsUser();
        [$map, $device] = $this->mapWithDevice();

        $share = $this->postJson("/api/maps/{$map->id}/shares", ['label' => 'Lobby TV'])
            ->assertCreated()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.label', 'Lobby TV');

        $url = $share->json('data.url');
        $this->assertStringContainsString('/wall/', $url);
        $token = str($url)->afterLast('/wall/')->toString();

        // The public endpoints work with no authentication at all.
        app('auth')->forgetGuards();
        $this->getJson("/api/public/wall/{$token}/map")->assertOk()->assertJsonPath('data.name', 'NOC');
        $this->getJson("/api/public/wall/{$token}/devices")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'core1');
    }

    public function test_public_device_payload_never_leaks_secrets(): void
    {
        [$map, $device] = $this->mapWithDevice();
        $token = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => true])->token;

        $res = $this->getJson("/api/public/wall/{$token}/devices")->assertOk();

        // The management address must be nulled, and no credential/agent fields present.
        $res->assertJsonPath('data.0.mgmt_ip', null);
        $body = $res->json('data.0');
        foreach (['credential_id', 'ssh_credential_id', 'routeros_credential_id', 'agent_id', 'serial', 'poll_method'] as $secret) {
            $this->assertArrayNotHasKey($secret, $body, "public payload leaked {$secret}");
        }
        // The raw IP string must not appear anywhere in the response.
        $this->assertStringNotContainsString('10.9.9.9', $res->getContent());
    }

    public function test_disabled_and_unknown_tokens_404(): void
    {
        [$map] = $this->mapWithDevice();
        $disabled = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => false]);

        $this->getJson("/api/public/wall/{$disabled->token}/map")->assertNotFound();
        $this->getJson('/api/public/wall/deadbeefdeadbeef/map')->assertNotFound();
        $this->get("/wall/{$disabled->token}")->assertNotFound();
    }

    public function test_non_admin_cannot_mint_a_share(): void
    {
        $viewer = User::factory()->create(['is_admin' => false]);
        [$map] = $this->mapWithDevice();

        $this->actingAs($viewer)
            ->postJson("/api/maps/{$map->id}/shares", ['label' => 'nope'])
            ->assertForbidden();
    }

    public function test_revoking_a_share_kills_the_link(): void
    {
        $this->actingAsUser();
        [$map] = $this->mapWithDevice();
        $share = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => true]);

        $this->getJson("/api/public/wall/{$share->token}/map")->assertOk();
        $this->deleteJson("/api/maps/{$map->id}/shares/{$share->id}")->assertNoContent();
        $this->getJson("/api/public/wall/{$share->token}/map")->assertNotFound();
    }
}
