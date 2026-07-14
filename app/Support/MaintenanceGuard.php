<?php

namespace App\Support;

use App\Models\MaintenanceWindow;

/**
 * A snapshot of which devices are currently under a maintenance window, built once per
 * alert-evaluation run. `covers()` answers whether a device's alerts should be suppressed.
 * A window scoped to "all" suppresses the whole fleet.
 */
class MaintenanceGuard
{
    private bool $all = false;

    /** @var array<int, true> */
    private array $ids = [];

    public function __construct()
    {
        foreach (MaintenanceWindow::query()->active()->get() as $window) {
            $ids = DeviceScope::resolve($window->scope);
            if ($ids === null) { // fleet-wide window
                $this->all = true;

                return;
            }
            foreach ($ids as $id) {
                $this->ids[$id] = true;
            }
        }
    }

    public function covers(int $deviceId): bool
    {
        return $this->all || isset($this->ids[$deviceId]);
    }

    /** True when at least one device is under maintenance (lets the evaluator skip work). */
    public function any(): bool
    {
        return $this->all || $this->ids !== [];
    }
}
