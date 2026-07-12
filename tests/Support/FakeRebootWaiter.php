<?php

namespace Tests\Support;

use App\Models\Device;
use App\Services\Upgrade\DeviceRebootWaiter;

/**
 * In-memory DeviceRebootWaiter for tests - no real sleeping. Records the device ids
 * it was asked to wait on (in order) and returns `$result` immediately.
 */
class FakeRebootWaiter implements DeviceRebootWaiter
{
    /** @var list<int> */
    public array $awaited = [];

    public function __construct(public bool $result = true) {}

    public function awaitRecovery(Device $device): bool
    {
        $this->awaited[] = $device->id;

        return $this->result;
    }
}
