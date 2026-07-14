<?php

namespace Tests\Feature;

use App\Actions\Import\ImportFromLibreNms;
use App\Enums\PollMethod;
use App\Models\Credential;
use App\Models\Device;
use App\Services\Import\LibreNms\LibreNmsApiSource;
use App\Services\Import\LibreNms\LibreNmsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibreNmsImportTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $ip, array $over = []): array
    {
        return array_merge([
            'hostname' => $ip, 'ip' => $ip, 'snmp_community' => null, 'snmp_version' => 'v2c',
            'os' => null, 'hardware' => null, 'serial' => null, 'sysname' => null, 'disabled' => false,
        ], $over);
    }

    private function source(array $devices, array $maps = []): LibreNmsSource
    {
        return new class($devices, $maps) implements LibreNmsSource
        {
            public function __construct(private array $d, private array $m) {}

            public function devices(): array
            {
                return $this->d;
            }

            public function maps(): array
            {
                return $this->m;
            }
        };
    }

    public function test_imports_devices_and_dedupes_a_credential_per_community(): void
    {
        $source = $this->source([
            $this->device('10.0.0.1', ['snmp_community' => 'public', 'os' => 'routeros', 'hardware' => 'RB750', 'sysname' => 'r1']),
            $this->device('10.0.0.2', ['snmp_community' => 'public', 'os' => 'ios', 'sysname' => 'r2']),
        ]);

        $summary = app(ImportFromLibreNms::class)($source, ['import_credentials' => true]);

        $this->assertSame(2, $summary['devices']['created']);
        $this->assertSame(1, $summary['credentials']); // one shared "public" credential
        $this->assertSame(1, Credential::count());

        $d = Device::where('mgmt_ip', '10.0.0.1')->firstOrFail();
        $this->assertSame('r1', $d->name);
        $this->assertSame('MikroTik', $d->vendor);
        $this->assertSame(PollMethod::Snmp, $d->poll_method);
    }

    public function test_applies_one_credential_to_all_devices(): void
    {
        $cred = Credential::factory()->create();
        $source = $this->source([$this->device('10.0.0.1'), $this->device('10.0.0.2')]);

        app(ImportFromLibreNms::class)($source, ['credential_id' => $cred->id]);

        $this->assertSame($cred->id, Device::where('mgmt_ip', '10.0.0.1')->value('credential_id'));
        $this->assertSame($cred->id, Device::where('mgmt_ip', '10.0.0.2')->value('credential_id'));
    }

    public function test_maps_specific_devices_to_credentials(): void
    {
        $a = Credential::factory()->create();
        $b = Credential::factory()->create();
        $source = $this->source([$this->device('10.0.0.1'), $this->device('10.0.0.2')]);

        app(ImportFromLibreNms::class)($source, ['credential_map' => ['10.0.0.1' => $a->id, '10.0.0.2' => $b->id]]);

        $this->assertSame($a->id, Device::where('mgmt_ip', '10.0.0.1')->value('credential_id'));
        $this->assertSame($b->id, Device::where('mgmt_ip', '10.0.0.2')->value('credential_id'));
    }

    public function test_imports_maps_with_positions(): void
    {
        $source = $this->source(
            [$this->device('10.0.0.1', ['snmp_community' => 'public'])],
            [['name' => 'Core', 'nodes' => [['ip' => '10.0.0.1', 'hostname' => 'r1', 'x' => 120.0, 'y' => 240.0, 'label' => 'r1']]]],
        );

        $summary = app(ImportFromLibreNms::class)($source, ['import_credentials' => true, 'import_maps' => true]);

        $this->assertSame(1, $summary['maps']);
        $this->assertSame(1, $summary['positions']);
        $device = Device::where('mgmt_ip', '10.0.0.1')->firstOrFail();
        $this->assertDatabaseHas('device_map_positions', ['device_id' => $device->id, 'x' => 120, 'y' => 240]);
    }

    public function test_skips_a_device_without_a_usable_ip(): void
    {
        $source = $this->source([$this->device('dns-only', ['ip' => null, 'hostname' => 'dns-only.example'])]);

        $summary = app(ImportFromLibreNms::class)($source, []);

        $this->assertSame(1, $summary['devices']['skipped']);
        $this->assertSame(0, Device::count());
    }

    public function test_reimport_updates_rather_than_duplicates(): void
    {
        $source = $this->source([$this->device('10.0.0.1', ['sysname' => 'first'])]);
        app(ImportFromLibreNms::class)($source, []);

        $again = $this->source([$this->device('10.0.0.1', ['sysname' => 'renamed'])]);
        $summary = app(ImportFromLibreNms::class)($again, []);

        $this->assertSame(1, $summary['devices']['updated']);
        $this->assertSame(1, Device::count());
        $this->assertSame('renamed', Device::where('mgmt_ip', '10.0.0.1')->value('name'));
    }

    public function test_api_source_parses_devices(): void
    {
        Http::fake(['*/api/v0/devices' => Http::response([
            'status' => 'ok',
            'devices' => [['hostname' => 'sw1', 'ip' => '10.0.0.5', 'os' => 'ios', 'hardware' => 'C3560', 'sysName' => 'sw1', 'disabled' => 0]],
        ])]);

        $devices = (new LibreNmsApiSource('http://librenms.example', 'TOKEN'))->devices();

        $this->assertCount(1, $devices);
        $this->assertSame('10.0.0.5', $devices[0]['ip']);
        $this->assertSame('sw1', $devices[0]['sysname']);
    }

    public function test_preview_and_import_endpoints_for_the_api(): void
    {
        Http::fake(['*/api/v0/devices' => Http::response(['devices' => [['hostname' => 'sw1', 'ip' => '10.0.0.5']]])]);
        $this->actingAsUser();
        $cred = Credential::factory()->create();

        $this->postJson('/api/imports/librenms/preview', ['driver' => 'api', 'base_url' => 'http://librenms.example', 'token' => 'T'])
            ->assertOk()->assertJsonPath('data.device_count', 1);

        $this->postJson('/api/imports/librenms', ['driver' => 'api', 'base_url' => 'http://librenms.example', 'token' => 'T', 'credential_id' => $cred->id])
            ->assertOk()->assertJsonPath('data.devices.created', 1);

        $this->assertSame($cred->id, Device::where('mgmt_ip', '10.0.0.5')->value('credential_id'));
    }
}
