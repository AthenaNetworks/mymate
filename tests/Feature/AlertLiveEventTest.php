<?php

namespace Tests\Feature;

use App\Events\AlertStateChanged;
use App\Models\AlertEvent;
use App\Models\AlertPolicy;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Support\RestrictedAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Port (and device) alerts surface live on the map screen (GitHub #22). An alert going firing, or a
 * firing one resolving, pushes AlertStateChanged; a breach that clears before it ever fired stays
 * silent, and a restricted operator only hears about devices on their maps.
 */
class AlertLiveEventTest extends TestCase
{
    use RefreshDatabase;

    private function fire(string $key, string $status = 'firing'): AlertEvent
    {
        return AlertEvent::create([
            'alert_policy_id' => AlertPolicy::factory()->create()->id,
            'dedupe_key' => $key, 'status' => $status, 'message' => 'ether1 is down.',
            'fired_at' => $status === 'firing' ? now() : null,
        ]);
    }

    public function test_firing_then_resolving_pushes_both_states(): void
    {
        Event::fake([AlertStateChanged::class]);
        $event = $this->fire('device:12:iface:40');
        $event->update(['status' => 'resolved', 'resolved_at' => now()]);

        $states = [];
        Event::assertDispatched(AlertStateChanged::class, function (AlertStateChanged $e) use (&$states): bool {
            $states[] = $e->state;
            $payload = $e->broadcastWith();

            return $payload['device_id'] === 12 && $payload['interface_id'] === 40;
        });
        $this->assertSame(['firing', 'resolved'], $states);
    }

    public function test_a_pending_breach_that_clears_stays_silent(): void
    {
        Event::fake([AlertStateChanged::class]);
        $event = $this->fire('device:5', 'pending');
        $event->update(['status' => 'resolved', 'resolved_at' => now()]);

        Event::assertNotDispatched(AlertStateChanged::class);
    }

    public function test_resaving_a_firing_alert_does_not_repeat_the_notice(): void
    {
        Event::fake([AlertStateChanged::class]);
        $event = $this->fire('device:5');
        $event->update(['delivered' => true]);

        Event::assertDispatchedTimes(AlertStateChanged::class, 1);
    }

    public function test_restricted_operators_only_get_their_own_devices(): void
    {
        Event::fake([AlertStateChanged::class]);
        $site = Map::create(['name' => 'Site A']);
        $user = User::factory()->create(['is_admin' => false]);
        $user->forceFill(['restricted' => true])->save();
        $user->maps()->attach($site->id);
        RestrictedAudience::forget();
        $mine = Device::factory()->create();
        DeviceMapPosition::create(['map_id' => $site->id, 'device_id' => $mine->id, 'x' => 0, 'y' => 0]);
        $other = Device::factory()->create();

        $this->fire("device:{$mine->id}:iface:1");
        $this->fire("device:{$other->id}:iface:2");
        $this->fire('agent:3'); // no device - shared channel only

        $userChannel = "private-map.user.{$user->id}";
        Event::assertDispatched(AlertStateChanged::class, fn (AlertStateChanged $e) => $e->broadcastOn()->name === $userChannel && $e->deviceId === $mine->id);
        Event::assertNotDispatched(AlertStateChanged::class, fn (AlertStateChanged $e) => $e->broadcastOn()->name === $userChannel && $e->deviceId !== $mine->id);
        Event::assertDispatchedTimes(AlertStateChanged::class, 4); // 3 shared + 1 scoped
    }

    public function test_status_filter_lists_only_firing_alerts(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fire('device:1');
        $this->fire('device:2')->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->fire('device:3', 'pending');

        $this->getJson('/api/alert-events?status=firing')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/alert-events')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_interfaces_report_oper_status(): void
    {
        $this->actingAs(User::factory()->create());
        $device = Device::factory()->create();
        NetworkInterface::factory()->create(['device_id' => $device->id, 'oper_status' => 'down']);

        $this->getJson("/api/devices/{$device->id}/interfaces")->assertOk()->assertJsonPath('data.0.oper_status', 'down');
    }
}
