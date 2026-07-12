<?php

namespace App\Actions\Devices;

use App\Enums\UpgradeStatus;
use App\Models\Device;
use App\Support\EngineLog;
use Throwable;

/**
 * Ordered bulk upgrade: a pre-flight (UpgradePreflight) orders the
 * devices furthest-downstream-first and decides which to upgrade vs skip (down /
 * parent down / not RouterOS / already up to date). Skipped devices are marked up
 * front (so the UI clears their queued spinner with a reason); the rest are upgraded
 * downstream-first - `UpgradeDevice` waits for each to recover before its parent, so
 * a reboot never cuts the path to gear still pending.
 *
 * Per-device failures are isolated (logged, the sequence continues).
 */
class RunBulkUpgrade
{
    public function __construct(
        private UpgradeDevice $upgrade,
        private UpgradePreflight $preflight,
    ) {}

    /** @param list<int> $deviceIds */
    public function __invoke(array $deviceIds): void
    {
        $plan = ($this->preflight)($deviceIds);

        // Mark skipped devices so their queued spinner clears with the reason.
        foreach ($plan['plan'] as $row) {
            if ($row['action'] === 'skip') {
                Device::where('id', $row['device_id'])->update([
                    'upgrade_status' => $row['reason'] === 'already up to date'
                        ? UpgradeStatus::UpToDate->value
                        : UpgradeStatus::Failed->value,
                    'upgrade_message' => ucfirst((string) $row['reason']).'.',
                    'upgrade_at' => now(),
                ]);
            }
        }

        $order = $plan['upgrade']; // upgradable only, downstream-first
        EngineLog::info('bulk-upgrade: starting', [
            'upgrade' => count($order),
            'skipped' => count($plan['plan']) - count($order),
            'order' => $order,
        ]);

        foreach ($order as $id) {
            $device = Device::find($id);
            if ($device === null) {
                continue;
            }

            try {
                ($this->upgrade)($device);
            } catch (Throwable $e) {
                EngineLog::error('bulk-upgrade: device failed, continuing', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        EngineLog::info('bulk-upgrade: complete', ['count' => count($order)]);
    }
}
