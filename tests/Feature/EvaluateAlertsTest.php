<?php

namespace Tests\Feature;

use App\Actions\Alerts\EvaluateAlerts;
use App\Enums\AlertCondition;
use App\Enums\BackupStatus;
use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Enums\DiscoveryStatus;
use App\Enums\UpgradeStatus;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\AlertTransport;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\MaintenanceWindow;
use App\Models\DiscoveryCandidate;
use App\Models\Link;
use App\Models\Map;
use App\Models\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EvaluateAlertsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string,mixed>  $params
     * @param  array<string,mixed>|null  $scope
     */
    private function policyWithSlack(AlertCondition $condition, array $params = [], ?array $scope = null): AlertPolicy
    {
        $transport = AlertTransport::factory()->create(); // slack webhook
        $policy = AlertPolicy::factory()->create(['condition' => $condition, 'params' => $params, 'scope' => $scope]);
        $policy->transports()->attach($transport);

        return $policy;
    }

    public function test_probe_down_fires_and_resolves_when_it_recovers(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::ProbeDown);
        $device = \App\Models\Device::factory()->create(['name' => 'PORTAL']);
        $probe = \App\Models\Probe::factory()->create([
            'device_id' => $device->id, 'name' => 'Web UI', 'status' => DeviceStatus::Down, 'message' => 'HTTP 503',
        ]);

        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', [
            'alert_policy_id' => $policy->id,
            'dedupe_key' => "device:{$device->id}:probe:{$probe->id}",
            'status' => 'firing',
        ]);

        // Probe recovers -> the event resolves. (status is a result column, not mass-assignable.)
        $probe->forceFill(['status' => DeviceStatus::Up])->save();
        app(EvaluateAlerts::class)();
        $this->assertSame('resolved', AlertEvent::firstOrFail()->status);
    }

    public function test_device_down_fires_dedupes_resolves_and_delivers(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::DeviceDown);
        $device = Device::factory()->create(['name' => 'CPE1', 'status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', [
            'alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}", 'status' => 'firing', 'delivered' => true,
        ]);
        Http::assertSentCount(1);

        // Re-run while still down: no duplicate event, no extra delivery.
        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::count());
        Http::assertSentCount(1);

        // Recovery resolves the event.
        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();
        $event = AlertEvent::firstOrFail();
        $this->assertSame('resolved', $event->status);
        $this->assertNotNull($event->resolved_at);
    }

    public function test_backup_failed_fires_dedupes_and_resolves_when_a_backup_succeeds(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::BackupFailed);
        $device = Device::factory()->create([
            'name' => 'CORE1', 'backup_status' => BackupStatus::Failed, 'backup_message' => 'ssh timeout',
        ]);

        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', [
            'alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}:backup",
            'status' => 'firing', 'delivered' => true,
        ]);
        Http::assertSentCount(1);

        // Still failing on the next run: one event, no re-notify.
        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::count());
        Http::assertSentCount(1);

        // A later backup succeeds -> the alert resolves.
        $device->update(['backup_status' => BackupStatus::Ok]);
        app(EvaluateAlerts::class)();
        $this->assertSame('resolved', AlertEvent::firstOrFail()->status);
    }

    public function test_high_metric_fires_when_cpu_is_over_threshold_and_resolves_when_it_drops(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::HighMetric, ['metric' => 'cpu', 'threshold' => 90]);
        $device = Device::factory()->create(['name' => 'RTR1', 'cpu_pct' => 95, 'metrics_at' => now()]);

        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', [
            'alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}:metric:cpu", 'status' => 'firing',
        ]);
        Http::assertSentCount(1);

        $device->update(['cpu_pct' => 40]);
        app(EvaluateAlerts::class)();
        $this->assertSame('resolved', AlertEvent::firstOrFail()->status);
    }

    public function test_high_metric_fires_on_high_latency(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::HighMetric, ['metric' => 'latency', 'threshold' => 100]);
        $device = Device::factory()->create(['name' => 'BACKHAUL', 'rtt_ms' => 150, 'ping_at' => now()]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', [
            'alert_policy_id' => $policy->id, 'dedupe_key' => "device:{$device->id}:metric:latency", 'status' => 'firing',
        ]);
        Http::assertSentCount(1);
    }

    public function test_high_metric_ignores_a_stale_reading(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::HighMetric, ['metric' => 'temp', 'threshold' => 70]);
        // Hot, but the reading is a day old - the device stopped reporting, so it must not
        // alert on a frozen value (DeviceDown covers unreachable gear).
        Device::factory()->create(['temp_c' => 85, 'metrics_at' => now()->subDay()]);

        app(EvaluateAlerts::class)();

        $this->assertSame(0, AlertEvent::count());
        Http::assertNothingSent();
    }

    public function test_maintenance_window_suppresses_alerts_for_covered_devices(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown);
        Device::factory()->create(['name' => 'CPE1', 'status' => DeviceStatus::Down]);
        MaintenanceWindow::factory()->create(); // active, fleet-wide

        app(EvaluateAlerts::class)();

        $this->assertSame(0, AlertEvent::count());
        Http::assertNothingSent();
    }

    public function test_an_inactive_maintenance_window_does_not_suppress(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown);
        Device::factory()->create(['status' => DeviceStatus::Down]);
        MaintenanceWindow::factory()->upcoming()->create(); // starts in the future

        app(EvaluateAlerts::class)();

        $this->assertSame(1, AlertEvent::count());
    }

    public function test_a_maintenance_window_scoped_to_other_devices_still_alerts(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown);
        $down = Device::factory()->create(['status' => DeviceStatus::Down]);
        $other = Device::factory()->create();
        MaintenanceWindow::factory()->create(['scope' => ['type' => 'devices', 'device_ids' => [$other->id]]]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$down->id}", 'status' => 'firing']);
    }

    public function test_maintenance_freezes_an_already_firing_event_instead_of_resolving_it(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)(); // fires normally
        $this->assertSame('firing', AlertEvent::firstOrFail()->status);

        // Maintenance starts and the device recovers during it - the event must NOT resolve
        // (no spurious recovery notification), it stays frozen.
        MaintenanceWindow::factory()->create();
        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();

        $this->assertSame('firing', AlertEvent::firstOrFail()->status);
    }

    public function test_dependent_device_down_is_suppressed_behind_a_down_ancestor(): void
    {
        Http::fake();
        // gateway(down) -> dist(down) -> cpe(down): only the gateway (root cause) should fire.
        $policy = $this->policyWithSlack(AlertCondition::DeviceDown); // suppress_dependent defaults on
        $gateway = Device::factory()->create(['name' => 'CORE', 'status' => DeviceStatus::Down]);
        $dist = Device::factory()->create(['name' => 'DIST', 'status' => DeviceStatus::Down, 'parent_device_id' => $gateway->id]);
        $cpe = Device::factory()->create(['name' => 'CPE', 'status' => DeviceStatus::Down, 'parent_device_id' => $dist->id]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$gateway->id}", 'status' => 'firing']);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "device:{$dist->id}"]);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "device:{$cpe->id}"]);
        $this->assertSame(1, AlertEvent::count());

        // When the gateway recovers but the child stays down, the child is now root cause -> fires.
        $gateway->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$dist->id}", 'status' => 'firing']);
    }

    public function test_dependency_suppression_can_be_disabled_per_policy(): void
    {
        Http::fake();
        $policy = $this->policyWithSlack(AlertCondition::DeviceDown, ['suppress_dependent' => false]);
        $parent = Device::factory()->create(['status' => DeviceStatus::Down]);
        $child = Device::factory()->create(['status' => DeviceStatus::Down, 'parent_device_id' => $parent->id]);

        app(EvaluateAlerts::class)();

        // Both fire when suppression is off (the original always-alert behaviour).
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$parent->id}", 'status' => 'firing']);
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$child->id}", 'status' => 'firing']);
    }

    public function test_sustained_device_down_waits_for_the_duration_before_firing(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, ['duration_minutes' => 10]);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        // First tick: the breach starts -> pending, no fire, no delivery.
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'pending']);
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
        Http::assertNothingSent();

        // Still inside the 10-minute window -> stays pending.
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());

        // Back-date the breach past the window -> next tick promotes to firing + notifies.
        AlertEvent::query()->update(['breach_started_at' => now()->subMinutes(11)]);
        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'firing')->count());
        Http::assertSentCount(1);
        // The delivered text carries how long it was actually sustained before firing.
        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'sustained 11 min'));
    }

    public function test_a_device_that_recovers_before_the_duration_never_fires(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, ['duration_minutes' => 10]);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)(); // pending
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'pending']);

        $device->update(['status' => DeviceStatus::Up]); // recovers before sustaining
        app(EvaluateAlerts::class)(); // pending dropped - never fired, no recovery message either
        $this->assertSame(0, AlertEvent::count());
        Http::assertNothingSent();
    }

    public function test_recovery_waits_for_the_duration_before_resolving_and_notifying(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, ['duration_minutes' => 10]);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        // Sustain past the window -> fires. Back-date the breach further than the recovery
        // window we'll use below (21 vs 11 min) so the two backdated timestamps - set at
        // different real moments a few ms apart - leave a real ~10-minute gap between them,
        // not cancel out to ~0.
        app(EvaluateAlerts::class)();
        AlertEvent::query()->update(['breach_started_at' => now()->subMinutes(21)]);
        app(EvaluateAlerts::class)();
        Http::assertSentCount(1);

        // Recovers -> doesn't resolve immediately, starts the recovery clock.
        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'resolving']);
        Http::assertSentCount(1); // still just the down alert - no recovery message yet

        // Still inside the 10-minute recovery window -> stays resolving.
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'resolving']);
        Http::assertSentCount(1);

        // Back-date the recovery past the window -> next tick resolves + notifies once,
        // with the real total outage length (breach_started_at -> recovery_started_at).
        AlertEvent::query()->update(['recovery_started_at' => now()->subMinutes(11)]);
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'resolved']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'Resolved after')
            && str_contains((string) ($req->data()['text'] ?? ''), 'min'));
    }

    public function test_a_flap_during_the_recovery_window_reverts_to_firing_without_notifying(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, ['duration_minutes' => 10]);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        // Sustain past the window -> fires.
        app(EvaluateAlerts::class)();
        AlertEvent::query()->update(['breach_started_at' => now()->subMinutes(11)]);
        app(EvaluateAlerts::class)();
        Http::assertSentCount(1);

        // Recovers -> starts the recovery clock (resolving).
        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['status' => 'resolving']);

        // Drops again before the recovery window elapses - never really recovered.
        $device->update(['status' => DeviceStatus::Down]);
        app(EvaluateAlerts::class)();
        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$device->id}", 'status' => 'firing']);
        $this->assertSame(1, AlertEvent::count()); // still one event, not a new one
        Http::assertSentCount(1); // no new down message, no recovery message for the blip
    }

    public function test_device_down_scoped_to_a_device_type_only_fires_for_that_type(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, [], ['type' => 'device_type', 'device_type' => 'router']);
        $router = Device::factory()->create(['status' => DeviceStatus::Down, 'device_type' => DeviceType::Router]);
        $switch = Device::factory()->create(['status' => DeviceStatus::Down, 'device_type' => DeviceType::Switch]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$router->id}", 'status' => 'firing']);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "device:{$switch->id}"]);
        $this->assertSame(1, AlertEvent::count());
    }

    public function test_device_down_scoped_to_a_specific_device_list(): void
    {
        Http::fake();
        $a = Device::factory()->create(['status' => DeviceStatus::Down]);
        $b = Device::factory()->create(['status' => DeviceStatus::Down]);
        $this->policyWithSlack(AlertCondition::DeviceDown, [], ['type' => 'devices', 'device_ids' => [$a->id]]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$a->id}"]);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "device:{$b->id}"]);
    }

    public function test_device_down_scoped_to_a_map_only_fires_for_members(): void
    {
        Http::fake();
        $on = Device::factory()->create(['status' => DeviceStatus::Down]);
        $off = Device::factory()->create(['status' => DeviceStatus::Down]);
        $map = Map::create(['name' => 'Edge']);
        DeviceMapPosition::create(['device_id' => $on->id, 'map_id' => $map->id, 'x' => 0, 'y' => 0]);
        $this->policyWithSlack(AlertCondition::DeviceDown, [], ['type' => 'map', 'map_id' => $map->id]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "device:{$on->id}"]);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "device:{$off->id}"]);
    }

    public function test_high_util_respects_device_scope(): void
    {
        Http::fake();
        $inScope = $this->linkAtBps(950_000_000); // 95% - would breach
        $outScope = $this->linkAtBps(950_000_000);
        // Scope to the in-scope link's A-end device only.
        $this->policyWithSlack(AlertCondition::HighUtil, ['threshold' => 90], ['type' => 'devices', 'device_ids' => [$inScope->a_device_id]]);

        app(EvaluateAlerts::class)();

        $this->assertDatabaseHas('alert_events', ['dedupe_key' => "link:{$inScope->id}", 'status' => 'firing']);
        $this->assertDatabaseMissing('alert_events', ['dedupe_key' => "link:{$outScope->id}"]);
    }

    public function test_recovery_alert_is_sent_when_the_condition_clears(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown);
        $device = Device::factory()->create(['name' => 'CPE1', 'status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)(); // fires -> 1 delivery
        Http::assertSentCount(1);

        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)(); // resolves -> recovery delivery

        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains((string) ($req->data()['text'] ?? ''), 'Resolved'));
        $this->assertSame('resolved', AlertEvent::firstOrFail()->status);
    }

    public function test_recovery_alert_can_be_disabled_per_policy(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::DeviceDown, ['notify_recovery' => false]);
        $device = Device::factory()->create(['status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)();
        $device->update(['status' => DeviceStatus::Up]);
        app(EvaluateAlerts::class)();

        Http::assertSentCount(1); // firing only - no recovery notification
        $this->assertSame('resolved', AlertEvent::firstOrFail()->status);
    }

    /** A link whose A->B throughput is `$bpsOut` on a `$speedMbps` link (both ends that speed). */
    private function linkAtBps(int $bpsOut, int $speedMbps = 1000): Link
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        $aIf = NetworkInterface::factory()->for($a)->create(['speed_mbps' => $speedMbps, 'bps_out' => $bpsOut]);
        $bIf = NetworkInterface::factory()->for($b)->create(['speed_mbps' => $speedMbps, 'bps_out' => 0]);

        return Link::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id,
            'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
        ]);
    }

    public function test_high_util_fires_over_threshold_and_resolves_under(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::HighUtil, ['threshold' => 90]); // duration omitted = instant
        $link = $this->linkAtBps(950_000_000); // 95% of a 1 Gbps link

        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'firing')->where('dedupe_key', "link:{$link->id}")->count());

        $link->aInterface->update(['bps_out' => 200_000_000]); // 20% -> clears
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_low_throughput_fires_below_the_floor_and_resolves_above_it(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::LowThroughput, ['threshold' => 1]); // 1 Mbps floor
        $link = $this->linkAtBps(200_000); // 0.2 Mbps -> below the floor

        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'firing')->where('dedupe_key', "link:{$link->id}")->count());

        $link->aInterface->update(['bps_out' => 5_000_000]); // 5 Mbps -> back above the floor
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_interface_down_fires_for_a_down_port_on_an_up_device_and_resolves(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::InterfaceDown);
        $device = Device::factory()->create(['status' => \App\Enums\DeviceStatus::Up]);
        $port = NetworkInterface::factory()->for($device)->create(['name' => 'ether5', 'oper_status' => 'down']);

        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'firing')->where('dedupe_key', "device:{$device->id}:iface:{$port->id}")->count());

        $port->update(['oper_status' => 'up']); // port comes back
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_interface_down_ignores_ports_on_a_down_device(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::InterfaceDown);
        $device = Device::factory()->create(['status' => \App\Enums\DeviceStatus::Down]);
        NetworkInterface::factory()->for($device)->create(['oper_status' => 'down']);

        app(EvaluateAlerts::class)();

        // The whole device is down - device-down covers it, no per-port storm.
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_interface_down_never_fires_for_an_unpolled_port(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::InterfaceDown);
        $device = Device::factory()->create(['status' => \App\Enums\DeviceStatus::Up]);
        NetworkInterface::factory()->for($device)->create(['oper_status' => null]); // never polled

        app(EvaluateAlerts::class)();

        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
    }

    public function test_low_throughput_ignores_a_link_with_a_down_end(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::LowThroughput, ['threshold' => 1]);
        $link = $this->linkAtBps(0); // idle, would be "low"...
        $link->aDevice->update(['status' => \App\Enums\DeviceStatus::Down]); // ...but its end is down

        app(EvaluateAlerts::class)();

        // Device-down covers a down device; low-throughput must not double-alert on it.
        $this->assertSame(0, AlertEvent::where('dedupe_key', "link:{$link->id}")->count());
    }

    public function test_sustained_high_util_waits_for_the_duration_before_firing(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::HighUtil, ['threshold' => 90, 'duration_minutes' => 10]);
        $this->linkAtBps(950_000_000);

        // First tick: the breach starts -> pending, no fire, no delivery.
        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'pending')->count());
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());
        Http::assertNothingSent();

        // Still inside the 10-minute window -> stays pending.
        app(EvaluateAlerts::class)();
        $this->assertSame(0, AlertEvent::where('status', 'firing')->count());

        // Back-date the breach past the window -> next tick promotes to firing + notifies.
        AlertEvent::query()->update(['breach_started_at' => now()->subMinutes(11)]);
        app(EvaluateAlerts::class)();
        $this->assertSame(1, AlertEvent::where('status', 'firing')->count());
        Http::assertSentCount(1);
    }

    public function test_a_breach_that_clears_before_the_duration_never_fires(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::HighUtil, ['threshold' => 90, 'duration_minutes' => 10]);
        $link = $this->linkAtBps(950_000_000);

        app(EvaluateAlerts::class)(); // pending
        $this->assertSame(1, AlertEvent::where('status', 'pending')->count());

        $link->aInterface->update(['bps_out' => 100_000_000]); // drops to 10% before sustaining
        app(EvaluateAlerts::class)(); // pending dropped - never fired
        $this->assertSame(0, AlertEvent::count());
        Http::assertNothingSent();
    }

    public function test_upgrade_failed_and_new_discovery_fire(): void
    {
        Http::fake();
        $this->policyWithSlack(AlertCondition::UpgradeFailed);
        $this->policyWithSlack(AlertCondition::NewDiscovery);
        Device::factory()->create(['upgrade_status' => UpgradeStatus::Failed, 'upgrade_at' => now(), 'upgrade_message' => 'boom']);
        DiscoveryCandidate::factory()->create(['status' => DiscoveryStatus::New]);

        app(EvaluateAlerts::class)();

        $this->assertSame(2, AlertEvent::where('status', 'firing')->count());
    }

    public function test_email_transport_delivers_without_error(): void
    {
        Mail::fake();
        $transport = AlertTransport::factory()->email()->create();
        $policy = AlertPolicy::factory()->create(['condition' => AlertCondition::DeviceDown]);
        $policy->transports()->attach($transport);
        Device::factory()->create(['status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)();

        $this->assertTrue((bool) AlertEvent::firstOrFail()->delivered);
    }

    public function test_disabled_policy_does_not_fire(): void
    {
        Http::fake();
        $transport = AlertTransport::factory()->create();
        $policy = AlertPolicy::factory()->create(['condition' => AlertCondition::DeviceDown, 'enabled' => false]);
        $policy->transports()->attach($transport);
        Device::factory()->create(['status' => DeviceStatus::Down]);

        app(EvaluateAlerts::class)();

        $this->assertSame(0, AlertEvent::count());
        Http::assertNothingSent();
    }
}
