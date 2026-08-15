<?php

namespace Tests\Feature;

use App\Actions\Upgrade\FetchRouterosPackage;
use App\Jobs\FetchRouterosPackageJob;
use App\Models\RouterosPackage;
use App\Services\Upgrade\RouterosReleases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RouterosPackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_filename_and_url_by_major(): void
    {
        $r = new RouterosReleases;
        // RouterOS 7 orders version-first, 6 arch-first.
        $this->assertSame('routeros-7.15.3-arm.npk', $r->packageFilename('7.15.3', 'arm'));
        $this->assertSame('routeros-mipsbe-6.49.10.npk', $r->packageFilename('6.49.10', 'mipsbe'));
        $this->assertStringEndsWith('/7.15.3/routeros-7.15.3-arm.npk', $r->packageUrl('7.15.3', 'arm'));

        // RouterOS 7 x86 AND CHR (which reports arch x86_64) both use the single arch-less package -
        // there is no routeros-<v>-x86.npk / -x86_64.npk on MikroTik.
        $this->assertSame('routeros-7.16.npk', $r->packageFilename('7.16', 'x86_64'));
        $this->assertSame('routeros-7.16.npk', $r->packageFilename('7.16', 'x86'));
        $this->assertTrue(RouterosReleases::isValidArch('x86_64'));
    }

    public function test_channels_parse_newest_files_and_skip_empty(): void
    {
        Cache::flush();
        Http::fake([
            '*NEWEST7.stable' => Http::response('7.15.3 1719400000'),
            '*NEWEST7.long-term' => Http::response('0.00'),      // absent -> skipped
            '*NEWEST7.testing' => Http::response('7.16rc1 1719500000'),
            '*NEWEST6.stable' => Http::response('6.49.10 1600000000'),
            '*NEWEST6.long-term' => Http::response('6.48.6 1500000000'),
            '*NEWEST6.testing' => Http::response('0.00'),
        ]);

        $channels = (new RouterosReleases)->channels();
        $versions = collect($channels)->pluck('version')->all();

        $this->assertContains('7.15.3', $versions);
        $this->assertContains('6.49.10', $versions);
        $this->assertNotContains('0.00', $versions);
    }

    public function test_fetch_downloads_and_caches_the_package(): void
    {
        Storage::fake('local');
        Http::fake(['*download.mikrotik.com/*' => Http::response(str_repeat('N', 4096))]);

        $pkg = app(FetchRouterosPackage::class)->fetch('7.15.3', 'arm', 'stable');

        $this->assertSame('ready', $pkg->status);
        $this->assertSame(4096, $pkg->size_bytes);
        Storage::disk('local')->assertExists($pkg->path);
    }

    public function test_fetch_marks_failed_on_a_tiny_error_page(): void
    {
        Storage::fake('local');
        Http::fake(['*download.mikrotik.com/*' => Http::response('not found')]); // < 1KB

        $pkg = app(FetchRouterosPackage::class)->fetch('9.9.9', 'arm');

        $this->assertSame('failed', $pkg->status);
    }

    public function test_catalog_endpoint_lists_channels_and_packages(): void
    {
        Cache::flush();
        Http::fake(['*NEWEST*' => Http::response('7.15.3 1719400000')]);
        $this->actingAsUser();
        RouterosPackage::factory()->ready()->create();

        $this->getJson('/api/routeros/catalog')
            ->assertOk()
            ->assertJsonStructure(['data' => ['channels', 'arches', 'device_arches', 'retention_days', 'packages']]);
    }

    public function test_fetch_endpoint_queues_a_download(): void
    {
        Queue::fake();
        $this->actingAsUser();

        $this->postJson('/api/routeros/packages', ['version' => '7.15.3', 'arch' => 'arm', 'channel' => 'stable'])
            ->assertStatus(202)
            ->assertJsonPath('data.version', '7.15.3');

        Queue::assertPushed(FetchRouterosPackageJob::class);
    }

    public function test_fetch_endpoint_rejects_a_bad_arch(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/routeros/packages', ['version' => '7.15.3', 'arch' => 'pentium'])
            ->assertStatus(422)->assertJsonValidationErrors('arch');
    }

    public function test_delete_removes_a_cached_package(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('routeros-packages/x.npk', 'DATA');
        $this->actingAsUser();
        $pkg = RouterosPackage::factory()->ready()->create(['path' => 'routeros-packages/x.npk']);

        $this->deleteJson("/api/routeros/packages/{$pkg->id}")->assertNoContent();

        $this->assertDatabaseCount('routeros_packages', 0);
        Storage::disk('local')->assertMissing('routeros-packages/x.npk');
    }

    public function test_router_download_endpoint_serves_a_ready_package_without_auth(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('routeros-packages/y.npk', str_repeat('P', 2048));
        $pkg = RouterosPackage::factory()->ready()->create(['path' => 'routeros-packages/y.npk', 'token' => 'TESTTOKEN123']);

        // No actingAs - routers can't authenticate; the token gates it.
        $this->get('/rospkg/TESTTOKEN123')->assertOk();
        $this->get('/rospkg/wrongtoken')->assertNotFound();
    }
}
