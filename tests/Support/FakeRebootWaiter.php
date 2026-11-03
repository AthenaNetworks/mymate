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

    /** @param  ?\Closure  $onRecover  run before returning - e.g. re-script the router as it comes back */
    public function __construct(public bool $result = true, public ?\Closure $onRecover = null) {}

    public function awaitRecovery(Device $device): bool
    {
        $this->awaited[] = $device->id;
        if ($this->onRecover !== null) {
            ($this->onRecover)($device);
        }

        return $this->result;
    }
}
