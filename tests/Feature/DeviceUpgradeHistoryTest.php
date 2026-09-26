<?php

namespace Tests\Feature;

use App\Actions\Devices\RunBulkUpgrade;
use App\Actions\Devices\UpgradeDevice;
use App\Actions\Devices\UpgradePreflight;
use App\Actions\System\FactoryReset;
use App\Actions\Upgrade\RecordUpgradeStatus;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\DeviceUpgrade;
use App\Models\Map;
use App\Models\User;
use App\Support\DeviceHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRebootWaiter;
use Tests\Support\FakeRouterOsClient;
use Tests\TestCase;

class DeviceUpgradeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function routerOsDevice(array $attrs = []): Device
    {
        return Device::factory()->create([
            'poll_method' => PollMethod::RouterOs,
            'credential_id' => Credential::factory()->routeros()->create()->id,
            ...$attrs,
        ]);
    }

    private function clientWithUpdate(): FakeRouterOsClient
    {
        return new FakeRouterOsClient(replies: [
            '/system/package/update/print' => [['installed-version' => '7.14', 'latest-version' => '7.15.2']],
            '/system/resource/print' => [['version' => '7.15.2 (stable)']],
        ]);
    }

    public function test_a_queued_then_run_upgrade_is_one_row_with_versions_who_and_timing(): void
    {
        Queue::fake();
        $user = $this->actingAsUser();
        $device = $this->routerOsDevice(['os_version' => '7.13']);

        $this->postJson('/api/devices/upgrade', ['device_ids' => [$device->id]])->assertStatus(202);

        $row = DeviceUpgrade::sole();
        $this->assertSame(UpgradeStatus::Queued, $row->status);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNull($row->batch_id); // single device, no batch
        $this->assertNull($row->finished_at);

        $this->travel(3)->minutes();
        (new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter(result: true)))($device->fresh());

        $row = DeviceUpgrade::sole()->fresh();
        $this->assertSame(UpgradeStatus::Done, $row->status);
        $this->assertSame('7.14', $row->from_version); // the live read wins over the stale column
        $this->assertSame('7.15.2', $row->to_version);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotNull($row->queued_at);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->finished_at);

        // the device columns still track the latest state for the spinners
        $this->assertSame(UpgradeStatus::Done, $device->fresh()->upgrade_status);
    }

    public function test_every_attempt_is_kept_and_a_bulk_request_shares_a_batch(): void
    {
        Queue::fake();
        $this->actingAsUser();
        $a = $this->routerOsDevice();
        $b = $this->routerOsDevice();

        $this->postJson('/api/devices/upgrade', ['device_ids' => [$a->id, $b->id], 'ordered' => true])->assertStatus(202);
        $batches = DeviceUpgrade::pluck('batch_id')->unique();
        $this->assertCount(1, $batches);
        $this->assertNotNull($batches->first());

        // queueing again while the first never reported back closes it off, not overwrites it
        $this->postJson('/api/devices/upgrade', ['device_ids' => [$a->id]])->assertStatus(202);
        $rows = DeviceUpgrade::where('device_id', $a->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(UpgradeStatus::Failed, $rows[0]->status);
        $this->assertNotNull($rows[0]->finished_at);
        $this->assertSame(UpgradeStatus::Queued, $rows[1]->status);
    }

    public function test_a_run_that_died_half_way_is_closed_when_the_next_one_starts(): void
    {
        $device = $this->routerOsDevice();
        $record = new RecordUpgradeStatus;
        $record($device, UpgradeStatus::Checking, 'Checking...');
        $record($device, UpgradeStatus::Rebooting, 'Rebooting...');

        // worker got killed, next run straight from the action (no queue step)
        (new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter(result: true)))($device);

        $rows = DeviceUpgrade::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(UpgradeStatus::Failed, $rows[0]->status);
        $this->assertSame(UpgradeStatus::Done, $rows[1]->status);
    }

    public function test_skips_and_failures_are_recorded(): void
    {
        $down = $this->routerOsDevice(['status' => DeviceStatus::Down]);
        (new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter))($down);
        $this->assertSame(UpgradeStatus::Failed, DeviceUpgrade::where('device_id', $down->id)->sole()->status);

        // did not come back after the reboot
        $flaky = $this->routerOsDevice();
        (new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter(result: false)))($flaky);
        $row = DeviceUpgrade::where('device_id', $flaky->id)->sole();
        $this->assertSame(UpgradeStatus::Failed, $row->status);
        $this->assertSame('7.14', $row->from_version);
        $this->assertSame('7.15.2', $row->to_version);

        // bulk run: a device the preflight skips still gets its row closed with the reason
        $snmp = Device::factory()->create(['poll_method' => PollMethod::Snmp]);
        (new RecordUpgradeStatus)($snmp, UpgradeStatus::Queued, 'Queued for upgrade...');
        $bulk = new RunBulkUpgrade(new UpgradeDevice($this->clientWithUpdate(), new FakeRebootWaiter), new UpgradePreflight(new DeviceHierarchy));
        $bulk([$snmp->id]);
        $row = DeviceUpgrade::where('device_id', $snmp->id)->sole();
        $this->assertSame(UpgradeStatus::Failed, $row->status);
        $this->assertNotNull($row->finished_at);
    }

    public function test_events_list_each_upgrade_and_the_summary_has_the_last_one(): void
    {
        $user = $this->actingAsUser();
        $device = $this->routerOsDevice();
        DeviceUpgrade::create([
            'device_id' => $device->id, 'user_id' => $user->id, 'status' => UpgradeStatus::Done, 'from_version' => '7.14', 'to_version' => '7.15.2',
            'queued_at' => now()->subDays(2), 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)->addMinutes(3),
        ]);
        DeviceUpgrade::create([
            'device_id' => $device->id, 'status' => UpgradeStatus::Failed, 'message' => 'Did not come back online after reboot.',
            'from_version' => '7.15.2', 'to_version' => '7.16', 'started_at' => now()->subHour(), 'finished_at' => now()->subMinutes(50),
        ]);

        $events = $this->getJson("/api/devices/{$device->id}/events?types[]=upgrade")->assertOk()->json('data');
        $this->assertCount(2, $events);
        $this->assertSame('Upgrade failed: Did not come back online after reboot', $events[0]['title']);
        $this->assertSame('7.15.2 -> 7.16 (took 10m 0s)', $events[0]['detail']);
        $this->assertSame('Upgraded 7.14 -> 7.15.2 (took 3m 0s)', $events[1]['title']);
        $this->assertSame("by {$user->name}", $events[1]['detail']);
        $this->assertSame(180, $events[1]['duration_s']);

        $last = $this->getJson("/api/devices/{$device->id}/summary")->assertOk()->json('data.last_upgrade');
        $this->assertSame('failed', $last['kind']);
    }

    public function test_restricted_operator_only_sees_upgrades_of_visible_devices(): void
    {
        $mapA = Map::factory()->create();
        $mapB = Map::factory()->create();
        $mine = $this->routerOsDevice();
        $hidden = $this->routerOsDevice();
        DeviceMapPosition::create(['device_id' => $mine->id, 'map_id' => $mapA->id, 'x' => 0, 'y' => 0]);
        DeviceMapPosition::create(['device_id' => $hidden->id, 'map_id' => $mapB->id, 'x' => 0, 'y' => 0]);
        foreach ([$mine, $hidden] as $d) {
            (new RecordUpgradeStatus)($d, UpgradeStatus::Failed, 'nope');
        }

        $user = User::factory()->create(['is_admin' => false, 'restricted' => true]);
        $user->maps()->attach($mapA->id);
        $this->actingAs($user);

        $this->getJson("/api/devices/{$hidden->id}/events?types[]=upgrade")->assertNotFound();
        $this->getJson("/api/devices/{$mine->id}/events?types[]=upgrade")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_backfill_turns_the_device_columns_into_history(): void
    {
        $migration = require database_path('migrations/2026_10_01_000001_create_device_upgrades_table.php');
        $migration->down();

        $done = Device::factory()->create(['os_version' => '7.15.2', 'upgrade_status' => UpgradeStatus::Done, 'upgrade_message' => 'Upgraded to 7.15.2.', 'upgrade_at' => now()->subDay()]);
        $failed = Device::factory()->create(['os_version' => '7.14', 'latest_version' => '7.16', 'upgrade_status' => UpgradeStatus::Failed, 'upgrade_message' => 'boom', 'upgrade_at' => now()]);
        Device::factory()->create(); // never upgraded, no row

        $migration->up();

        $this->assertSame(2, DeviceUpgrade::count());
        $d = DeviceUpgrade::where('device_id', $done->id)->sole();
        $this->assertSame(UpgradeStatus::Done, $d->status);
        $this->assertNull($d->from_version);
        $this->assertSame('7.15.2', $d->to_version);
        $this->assertNotNull($d->finished_at);
        $f = DeviceUpgrade::where('device_id', $failed->id)->sole();
        $this->assertSame(['7.14', '7.16', 'boom'], [$f->from_version, $f->to_version, $f->message]);
    }

    public function test_history_goes_with_the_device_and_with_a_factory_reset(): void
    {
        $a = $this->routerOsDevice();
        $b = $this->routerOsDevice();
        (new RecordUpgradeStatus)($a, UpgradeStatus::Failed, 'x');
        (new RecordUpgradeStatus)($b, UpgradeStatus::Failed, 'x');

        $a->delete();
        $this->assertSame(0, DeviceUpgrade::where('device_id', $a->id)->count());
        $this->assertSame(1, DeviceUpgrade::count());

        User::factory()->admin()->create();
        (new FactoryReset)();
        $this->assertSame(0, (int) DB::table('device_upgrades')->count());
    }
}
