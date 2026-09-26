<?php

namespace Tests\Feature;

use App\Events\DeviceStatusChanged;
use App\Events\InterfaceUtilUpdated;
use App\Models\Device;
use App\Models\DeviceMapPosition;
use App\Models\Map;
use App\Models\User;
use App\Support\LiveBroadcast;
use App\Support\RestrictedAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The live map stream respects map restrictions (GitHub #28). The shared `map` channel carries the
 * whole fleet, so only unrestricted operators may join it; a restricted operator gets their own
 * channel carrying a copy filtered to their devices. Before this, any signed-in user could join
 * `map` and read live status for devices outside their maps off the websocket.
 */
class LiveChannelScopeTest extends TestCase
{
    use RefreshDatabase;

    private function restrictedTo(Map $map): User
    {
        $user = User::factory()->create(['is_admin' => false]);
        $user->forceFill(['restricted' => true])->save(); // privilege, not mass-assignable
        $user->maps()->attach($map->id);
        RestrictedAudience::forget();

        return $user;
    }

    private function deviceOn(Map $map): Device
    {
        $device = Device::factory()->create();
        DeviceMapPosition::create(['map_id' => $map->id, 'device_id' => $device->id, 'x' => 0, 'y' => 0]);

        return $device;
    }

    /** @return callable */
    private function channel(string $name)
    {
        return Broadcast::getChannels()[$name];
    }

    public function test_only_unrestricted_operators_may_join_the_shared_map_channel(): void
    {
        $open = User::factory()->create(['is_admin' => false]);
        $restricted = $this->restrictedTo(Map::create(['name' => 'Site A']));

        $this->assertTrue(($this->channel('map'))($open));
        $this->assertFalse(($this->channel('map'))($restricted));
    }

    public function test_a_restricted_operator_may_join_only_their_own_channel(): void
    {
        $alice = $this->restrictedTo(Map::create(['name' => 'Site A']));
        $bob = $this->restrictedTo(Map::create(['name' => 'Site B']));
        $open = User::factory()->create(['is_admin' => false]);

        $own = $this->channel('map.user.{id}');
        $this->assertTrue($own($alice, $alice->id));
        $this->assertFalse($own($alice, $bob->id));   // someone else's stream
        $this->assertFalse($own($open, $open->id));   // unrestricted users use the shared channel
    }

    public function test_a_status_change_reaches_only_operators_who_can_see_the_device(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        $siteA = Map::create(['name' => 'Site A']);
        $siteB = Map::create(['name' => 'Site B']);
        $alice = $this->restrictedTo($siteA);
        $bob = $this->restrictedTo($siteB);
        $device = $this->deviceOn($siteA);

        LiveBroadcast::send(new DeviceStatusChanged($device));

        $channels = [];
        Event::assertDispatched(DeviceStatusChanged::class, function (DeviceStatusChanged $e) use (&$channels): bool {
            $channels[] = $e->broadcastOn()->name;

            return true;
        });
        sort($channels);
        $this->assertSame(['private-map', "private-map.user.{$alice->id}"], $channels);
        $this->assertNotContains("private-map.user.{$bob->id}", $channels);
    }

    public function test_a_batched_event_is_filtered_to_each_operators_devices(): void
    {
        Event::fake([InterfaceUtilUpdated::class]);
        $siteA = Map::create(['name' => 'Site A']);
        $alice = $this->restrictedTo($siteA);
        $visible = $this->deviceOn($siteA);
        $hidden = Device::factory()->create(); // on no map Alice can see

        LiveBroadcast::send(new InterfaceUtilUpdated([
            ['device_id' => $visible->id, 'status' => 'up', 'interfaces' => []],
            ['device_id' => $hidden->id, 'status' => 'up', 'interfaces' => []],
        ]));

        Event::assertDispatched(InterfaceUtilUpdated::class, fn (InterfaceUtilUpdated $e) => $e->broadcastOn()->name === 'private-map' && count($e->devices) === 2);
        Event::assertDispatched(
            InterfaceUtilUpdated::class,
            fn (InterfaceUtilUpdated $e) => $e->broadcastOn()->name === "private-map.user.{$alice->id}"
                && array_column($e->devices, 'device_id') === [$visible->id],
        );
    }

    public function test_nothing_is_sent_to_an_operator_with_none_of_the_devices(): void
    {
        Event::fake([DeviceStatusChanged::class]);
        $this->restrictedTo(Map::create(['name' => 'Empty site']));
        $device = Device::factory()->create();

        LiveBroadcast::send(new DeviceStatusChanged($device));

        Event::assertDispatchedTimes(DeviceStatusChanged::class, 1); // just the shared channel
    }
}
