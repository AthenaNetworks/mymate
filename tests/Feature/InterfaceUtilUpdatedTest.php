<?php

namespace Tests\Feature;

use App\Events\InterfaceUtilUpdated;
use PHPUnit\Framework\TestCase;

class InterfaceUtilUpdatedTest extends TestCase
{
    public function test_broadcasts_coalesced_device_frames_on_map_channel(): void
    {
        $devices = [
            ['device_id' => 1, 'status' => 'up', 'interfaces' => [
                ['interface_id' => 7, 'device_id' => 1, 'util_in' => 12.5, 'util_out' => 3.0, 'speed_mbps' => 1000, 'bps_in' => 1.25e8, 'bps_out' => 3.0e7, 'status' => 'up'],
                ['interface_id' => 8, 'device_id' => 1, 'util_in' => null, 'util_out' => null, 'speed_mbps' => 0, 'bps_in' => null, 'bps_out' => null, 'status' => 'up'],
            ]],
            ['device_id' => 2, 'status' => 'down', 'interfaces' => [
                ['interface_id' => 9, 'device_id' => 2, 'util_in' => 0.0, 'util_out' => 0.0, 'speed_mbps' => 1000, 'bps_in' => 0.0, 'bps_out' => 0.0, 'status' => 'down'],
            ]],
        ];

        $event = new InterfaceUtilUpdated($devices);
        $payload = $event->broadcastWith();

        $this->assertSame('private-map', $event->broadcastOn()->name);
        $this->assertSame('InterfaceUtilUpdated', $event->broadcastAs());
        $this->assertSame($devices, $payload['devices']);
        $this->assertSame(2, $payload['device_count']);
        $this->assertSame(3, $payload['interface_count']); // 2 + 1 interfaces across devices
    }
}
