<?php

namespace Tests\Feature;

use App\Actions\System\FactoryReset;
use App\Models\Map;
use App\Models\MapShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Custom background image per map (GitHub #37). The security-critical properties: only admins
 * change it, the image of a map you can't see is a 404, only real images are accepted (by bytes,
 * not by name), a hostile SVG is neutralised or refused, and the file is always served sandboxed.
 */
class MapBackgroundTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function png(string $name = 'plan.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function svg(string $body, string $name = 'plan.svg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function upload(Map $map, UploadedFile $file)
    {
        return $this->post("/api/maps/{$map->id}/background", ['image' => $file], ['Accept' => 'application/json']);
    }

    public function test_admin_uploads_a_png_and_it_is_served_sandboxed(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $meta = $this->upload($map, $this->png())
            ->assertCreated()
            ->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.width', 1)
            ->assertJsonPath('data.height', 1)
            ->json('data');
        // No storage path leaks to the client.
        $this->assertArrayNotHasKey('path', $meta);

        $map->refresh();
        Storage::disk('local')->assertExists($map->background_path);

        $this->getJson("/api/maps/{$map->id}/background")->assertOk()->assertJsonPath('data.version', $map->background_version);

        $img = $this->get("/api/maps/{$map->id}/background/image")->assertOk();
        $img->assertHeader('Content-Type', 'image/png');
        $img->assertHeader('X-Content-Type-Options', 'nosniff');
        $img->assertHeader('X-Frame-Options', 'DENY');
        // The controller's sandbox policy survives the global middleware (sent alongside, not replaced).
        $csp = implode(' | ', $img->headers->all('content-security-policy'));
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
    }

    public function test_only_admins_can_change_it(): void
    {
        $map = Map::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->upload($map, $this->png())->assertForbidden();
        $this->patchJson("/api/maps/{$map->id}/background", ['opacity' => 0.2])->assertForbidden();
        $this->deleteJson("/api/maps/{$map->id}/background")->assertForbidden();
        $this->assertNull($map->fresh()->background_path);

        // ...but a normal operator can still see one that's set.
        $map->forceFill(['background_path' => 'map-backgrounds/x.png', 'background_mime' => 'image/png', 'background_version' => 'v1', 'background_width' => 1, 'background_height' => 1])->save();
        Storage::disk('local')->put('map-backgrounds/x.png', base64_decode(self::PNG));
        $this->getJson("/api/maps/{$map->id}/background")->assertOk()->assertJsonPath('data.version', 'v1');
        $this->get("/api/maps/{$map->id}/background/image")->assertOk();
    }

    public function test_background_of_a_map_the_operator_cannot_see_is_a_404(): void
    {
        $this->actingAsUser();
        $mine = Map::factory()->create();
        $theirs = Map::factory()->create();
        $this->upload($mine, $this->png())->assertCreated();
        $this->upload($theirs, $this->png())->assertCreated();

        $restricted = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $restricted->maps()->attach($mine->id);
        $this->actingAs($restricted);

        $this->getJson("/api/maps/{$theirs->id}/background")->assertNotFound();
        $this->get("/api/maps/{$theirs->id}/background/image")->assertNotFound();
        $this->getJson("/api/maps/{$mine->id}/background")->assertOk();
        $this->get("/api/maps/{$mine->id}/background/image")->assertOk();
    }

    public function test_malicious_svg_is_neutralised(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $evil = <<<'SVG'
<?xml version="1.0"?>
<?xml-stylesheet href="https://evil.test/x.css"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="800" height="600" onload="alert(1)">
  <script>alert(document.cookie)</script>
  <script xlink:href="https://evil.test/x.js"/>
  <defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs>
  <rect id="room" x="10" y="10" width="100" height="50" fill="url(#g)" onclick="alert(2)"/>
  <a href="javascript:alert(3)"><text x="5" y="5">click</text></a>
  <use xlink:href="https://evil.test/sprite.svg#x"/>
  <use href="#room" x="200"/>
  <image href="https://evil.test/track.png" width="1" height="1"/>
  <image href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==" width="1" height="1"/>
  <foreignObject width="100" height="100"><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="javascript:alert(4)"/></body></foreignObject>
  <animate attributeName="href" to="javascript:alert(5)"/>
  <set attributeName="onmouseover" to="alert(6)"/>
  <style>@import url(https://evil.test/x.css); rect { fill: blue }</style>
  <circle cx="1" cy="1" r="1" style="fill:url(https://evil.test/leak)"/>
  <circle cx="2" cy="2" r="1" STYLE="background:url(java&#x09;script:alert(7))"/>
</svg>
SVG;

        $this->upload($map, $this->svg($evil))
            ->assertCreated()
            ->assertJsonPath('data.mime', 'image/svg+xml')
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600);

        $stored = Storage::disk('local')->get($map->fresh()->background_path);
        foreach (['<script', 'onload', 'onclick', 'onmouseover', 'javascript', 'evil.test', 'foreignObject', 'iframe', '<animate', '<set', '@import', 'data:text', 'xml-stylesheet', '<a '] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $stored, "sanitised SVG still contains {$needle}");
        }
        // The harmless drawing is kept.
        $this->assertStringContainsString('<rect id="room"', $stored);
        $this->assertStringContainsString('fill="url(#g)"', $stored);
        $this->assertStringContainsString('href="#room"', $stored);
        $this->assertStringContainsString('<linearGradient', $stored);

        $this->get("/api/maps/{$map->id}/background/image")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_svg_with_a_doctype_or_entities_is_refused(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';
        $this->upload($map, $this->svg($xxe))->assertUnprocessable()->assertJsonValidationErrors('image');

        $lol = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&lol2;</text></svg>';
        $this->upload($map, $this->svg($lol))->assertUnprocessable();

        $this->assertNull($map->fresh()->background_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_real_images_are_accepted_whatever_the_file_is_called(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        // HTML dressed up as a PNG, and as an SVG.
        $this->upload($map, UploadedFile::fake()->createWithContent('plan.png', '<html><script>alert(1)</script></html>'))->assertUnprocessable();
        $this->upload($map, $this->svg('<html><script>alert(1)</script></html>'))->assertUnprocessable();
        // Well-formed XML that isn't SVG.
        $this->upload($map, $this->svg('<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body/></html>'))->assertUnprocessable();
        // A PNG signature on garbage.
        $this->upload($map, UploadedFile::fake()->createWithContent('plan.png', "\x89PNG\r\n\x1a\nnot really"))->assertUnprocessable();
        // Something else entirely (a GIF - not on the list).
        $this->upload($map, UploadedFile::fake()->createWithContent('plan.gif', base64_decode('R0lGODlhAQABAAAAACw=')))->assertUnprocessable();

        $this->assertNull($map->fresh()->background_path);
    }

    public function test_size_limits(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        config(['mymate.map.background_max_kb' => 1]);
        $this->upload($map, UploadedFile::fake()->createWithContent('big.png', base64_decode(self::PNG).str_repeat("\0", 4096)))
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        config(['mymate.map.background_max_kb' => 10240, 'mymate.map.background_max_px' => 0]);
        $this->upload($map, $this->png())->assertUnprocessable();
    }

    public function test_placement_is_validated_and_persisted(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();

        $this->patchJson("/api/maps/{$map->id}/background", ['x' => 1])->assertNotFound(); // nothing to place yet
        $this->upload($map, $this->png())->assertCreated();

        $this->patchJson("/api/maps/{$map->id}/background", ['x' => -120.5, 'y' => 40, 'scale' => 2.5, 'opacity' => 0.35])
            ->assertOk()
            ->assertJsonPath('data.x', -120.5)
            ->assertJsonPath('data.scale', 2.5)
            ->assertJsonPath('data.opacity', 0.35);

        $this->patchJson("/api/maps/{$map->id}/background", ['opacity' => 3])->assertUnprocessable();
        $this->patchJson("/api/maps/{$map->id}/background", ['scale' => 0])->assertUnprocessable();
        $this->patchJson("/api/maps/{$map->id}/background", ['x' => 'left'])->assertUnprocessable();

        // Replacing the image keeps the placement and drops the old file.
        $old = $map->fresh()->background_path;
        $this->upload($map, $this->png())->assertCreated()->assertJsonPath('data.scale', 2.5);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_remove_and_map_delete_clean_up_the_file(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $this->upload($map, $this->png())->assertCreated();
        $path = $map->fresh()->background_path;

        $this->deleteJson("/api/maps/{$map->id}/background")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        $this->getJson("/api/maps/{$map->id}/background")->assertOk()->assertJsonPath('data', null);
        $this->get("/api/maps/{$map->id}/background/image")->assertNotFound();

        $this->upload($map, $this->png())->assertCreated();
        $path = $map->fresh()->background_path;
        $this->deleteJson("/api/maps/{$map->id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);

        // A factory reset truncates maps without model events, so it clears the folder itself.
        $other = Map::factory()->create();
        $this->upload($other, $this->png())->assertCreated();
        app(FactoryReset::class)();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_public_wallboard_share_shows_the_background(): void
    {
        $this->actingAsUser();
        $map = Map::factory()->create();
        $bare = Map::factory()->create();
        $this->upload($map, $this->png())->assertCreated();
        $token = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => true])->token;
        $bareToken = MapShare::create(['map_id' => $bare->id, 'token' => MapShare::newToken(), 'enabled' => true])->token;
        $offToken = MapShare::create(['map_id' => $map->id, 'token' => MapShare::newToken(), 'enabled' => false])->token;

        app('auth')->forgetGuards();

        $this->getJson("/api/public/wall/{$token}/map")->assertOk()
            ->assertJsonPath('data.background.version', $map->fresh()->background_version);
        $img = $this->get("/api/public/wall/{$token}/background")->assertOk();
        $this->assertStringContainsString('sandbox', implode(' ', $img->headers->all('content-security-policy')));

        $this->getJson("/api/public/wall/{$bareToken}/map")->assertOk()->assertJsonPath('data.background', null);
        $this->get("/api/public/wall/{$bareToken}/background")->assertNotFound();
        $this->get("/api/public/wall/{$offToken}/background")->assertNotFound();
        $this->get('/api/public/wall/nosuchtoken/background')->assertNotFound();
    }
}
