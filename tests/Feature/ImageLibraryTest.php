<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\ImageAsset;
use App\Models\Map;
use App\Models\MapImage;
use App\Models\MapShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The image library (GitHub #53): custom device icons and pictures placed on maps. Same upload
 * guarantees as map backgrounds (checked by bytes, SVG sanitised, served sandboxed), admin-only
 * changes, and a public wallboard only gets the images its map actually uses.
 */
class ImageLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/image-assets', ['image' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    public function test_admin_uploads_and_it_is_served_sandboxed(): void
    {
        $this->actingAsUser();

        $res = $this->upload($this->png('Acme logo.png'))->assertCreated()
            ->assertJsonPath('data.name', 'Acme logo')
            ->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.width', 1)
            ->assertJsonMissingPath('data.path');
        $id = $res->json('data.id');

        $this->getJson('/api/image-assets')->assertOk()->assertJsonPath('data.0.id', $id);
        $file = $this->get("/api/image-assets/{$id}/file")->assertOk();
        $this->assertStringContainsString('sandbox', $file->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));
    }

    public function test_only_real_images_and_hostile_svg_is_neutralised(): void
    {
        $this->actingAsUser();

        $this->upload(UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;'))->assertUnprocessable();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="alert(1)"><script>alert(2)</script><rect width="10" height="10"/></svg>';
        $id = $this->upload(UploadedFile::fake()->createWithContent('i.svg', $svg))->assertCreated()->json('data.id');
        $stored = Storage::disk('local')->get(ImageAsset::findOrFail($id)->path);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
    }

    public function test_only_admins_change_the_library_or_place_images(): void
    {
        $asset = ImageAsset::create(['name' => 'a', 'path' => 'image-assets/a.png', 'mime' => 'image/png', 'version' => 'v', 'width' => 1, 'height' => 1]);
        $map = Map::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->upload($this->png())->assertForbidden();
        $this->deleteJson("/api/image-assets/{$asset->id}")->assertForbidden();
        $this->postJson("/api/maps/{$map->id}/images", ['image_asset_id' => $asset->id])->assertForbidden();
        $this->getJson('/api/image-assets')->assertOk(); // reading is fine, the map draws them
    }

    public function test_place_move_resize_and_remove_an_image_on_a_map(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $assetId = $this->upload($this->png())->json('data.id');

        $placed = $this->postJson("/api/maps/{$map->id}/images", ['image_asset_id' => $assetId, 'x' => 10, 'y' => 20])
            ->assertCreated()->assertJsonPath('data.width', 160)->assertJsonPath('data.asset.id', $assetId);
        $id = $placed->json('data.id');

        $this->patchJson("/api/maps/{$map->id}/images/{$id}", ['width' => 320, 'x' => 50])->assertOk()
            ->assertJsonPath('data.width', 320)->assertJsonPath('data.x', 50);
        $this->patchJson("/api/maps/{$map->id}/images/{$id}", ['width' => 2])->assertUnprocessable();
        $this->patchJson("/api/maps/{$map->id}/positions", ['images' => [['id' => $id, 'x' => 7, 'y' => 8]]])->assertOk();
        $this->assertSame(7.0, MapImage::findOrFail($id)->x);

        $this->getJson("/api/maps/{$map->id}")->assertOk()->assertJsonPath('data.map_images.0.id', $id);

        // Another map's image can't be reached through this one.
        $other = Map::factory()->create();
        $this->patchJson("/api/maps/{$other->id}/images/{$id}", ['x' => 1])->assertNotFound();

        $this->deleteJson("/api/maps/{$map->id}/images/{$id}")->assertNoContent();
        $this->assertNotNull(ImageAsset::find($assetId), 'removing it from the map keeps it in the library');
    }

    public function test_device_icon_from_the_library(): void
    {
        $this->actingAsUser();
        $assetId = $this->upload($this->png())->json('data.id');
        $device = Device::factory()->create();

        $this->putJson("/api/devices/{$device->id}", ['icon' => "img:{$assetId}"])->assertOk()->assertJsonPath('data.icon', "img:{$assetId}");
        $this->putJson("/api/devices/{$device->id}", ['icon' => 'img:999999'])->assertUnprocessable();

        // Deleting the image drops it from every map and puts the device back on its automatic icon.
        $map = Map::factory()->create();
        MapImage::create(['map_id' => $map->id, 'image_asset_id' => $assetId]);
        $path = ImageAsset::findOrFail($assetId)->path;

        $this->deleteJson("/api/image-assets/{$assetId}")->assertNoContent();

        $this->assertNull($device->fresh()->icon);
        $this->assertSame(0, MapImage::count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_wallboard_only_serves_images_its_map_uses(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $share = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => true]);
        $placed = $this->upload($this->png('placed.png'))->json('data.id');
        $icon = $this->upload($this->png('icon.png'))->json('data.id');
        $unused = $this->upload($this->png('secret.png'))->json('data.id');

        MapImage::create(['map_id' => $map->id, 'image_asset_id' => $placed]);
        $device = Device::factory()->create(['icon' => "img:{$icon}"]);
        $device->mapPositions()->create(['map_id' => $map->id, 'x' => 0, 'y' => 0]);

        $this->get("/api/public/wall/{$share->token}/images/{$placed}")->assertOk();
        $this->get("/api/public/wall/{$share->token}/images/{$icon}")->assertOk();
        $this->get("/api/public/wall/{$share->token}/images/{$unused}")->assertNotFound();
        $this->getJson("/api/public/wall/{$share->token}/map")->assertJsonPath('data.map_images.0.asset.id', $placed);
    }
}
