<?php

namespace Tests\Feature;

use App\Actions\Devices\FetchMikrotikIcon;
use App\Jobs\FetchDeviceIconJob;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceIconTest extends TestCase
{
    use RefreshDatabase;

    public function test_slug_derivation(): void
    {
        $this->assertSame('hap_ac2', FetchMikrotikIcon::slug('hAP ac²'));
        $this->assertSame('crs328_24p_4s', FetchMikrotikIcon::slug('CRS328-24P-4S+'));
        $this->assertSame('rb4011igs', FetchMikrotikIcon::slug('RB4011iGS+'));
    }

    public function test_fetch_scrapes_the_image_id_and_caches_the_photo(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://mikrotik.com/product/*' => Http::response('<img src="https://cdn.mikrotik.com/web-assets/rb_images/1468_lg.webp">'),
            'https://cdn.mikrotik.com/*' => Http::response('FAKE-WEBP-BYTES'),
        ]);

        $path = app(FetchMikrotikIcon::class)->fetch('hAP ac²');

        $this->assertSame('device-icons/mikrotik/hap_ac2.webp', $path);
        $this->assertSame('FAKE-WEBP-BYTES', Storage::disk('local')->get($path));
    }

    public function test_fetch_returns_null_when_the_page_has_no_image(): void
    {
        Storage::fake('local');
        Http::fake(['https://mikrotik.com/product/*' => Http::response('<html>no image here</html>')]);

        $this->assertNull(app(FetchMikrotikIcon::class)->fetch('unknownmodel'));
    }

    public function test_serves_a_cached_icon(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('device-icons/mikrotik/hap_ac2.webp', 'IMG');
        $this->actingAsUser();
        $device = Device::factory()->create(['vendor' => 'MikroTik', 'model' => 'hAP ac²']);

        $res = $this->get("/api/devices/{$device->id}/icon");

        $res->assertOk();
        $this->assertSame('image/webp', $res->headers->get('content-type'));
    }

    public function test_miss_dispatches_a_fetch_and_404s(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUser();
        $device = Device::factory()->create(['vendor' => 'MikroTik', 'model' => 'RB5009']);

        $this->get("/api/devices/{$device->id}/icon")->assertNotFound();

        Queue::assertPushed(FetchDeviceIconJob::class);
    }

    public function test_non_mikrotik_device_is_404_and_dispatches_nothing(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $device = Device::factory()->create(['vendor' => 'Ubiquiti', 'model' => 'NanoStation']);

        $this->get("/api/devices/{$device->id}/icon")->assertNotFound();

        Queue::assertNothingPushed();
    }
}
