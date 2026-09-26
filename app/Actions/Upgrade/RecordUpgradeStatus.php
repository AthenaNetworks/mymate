<?php

namespace App\Actions\Upgrade;

use App\Enums\UpgradeStatus;
use App\Models\Device;
use App\Models\DeviceUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * The one place an upgrade changes state. Sets the upgrade_* columns on the device (what the
 * UI spinners read) and keeps the matching device_upgrades row in step, so there's a history
 * of every attempt and not just the latest.
 *
 * An attempt is the open row (finished_at null) for the device. A new one starts when:
 *  - the upgrade is queued (anything still open is closed off as interrupted first), or
 *  - there's nothing open, e.g. UpgradeDevice called straight from a test or tinker, or
 *  - a worker starts checking but the open row is already past checking, which means the
 *    last run died half way (worker killed mid reboot) and never recorded an outcome.
 */
class RecordUpgradeStatus
{
    public function __invoke(
        Device $device,
        UpgradeStatus $status,
        ?string $message = null,
        ?string $toVersion = null,
        ?int $userId = null,
        ?string $batchId = null,
    ): DeviceUpgrade {
        return DB::transaction(function () use ($device, $status, $message, $toVersion, $userId, $batchId) {
            $now = now();

            $device->upgrade_status = $status;
            $device->upgrade_message = $message;
            $device->upgrade_at = $now;
            $device->save();

            $open = DeviceUpgrade::where('device_id', $device->id)->whereNull('finished_at')
                ->latest('id')->lockForUpdate()->first();

            $stale = $open !== null && (
                $status === UpgradeStatus::Queued
                || ($status === UpgradeStatus::Checking && $open->status !== UpgradeStatus::Queued)
            );
            if ($stale) {
                $open->update([
                    'status' => UpgradeStatus::Failed,
                    'message' => 'Never finished, interrupted before it reported back.',
                    'finished_at' => $now,
                ]);
                $open = null;
            }

            $row = $open ?? new DeviceUpgrade([
                'device_id' => $device->id,
                'user_id' => $userId,
                'batch_id' => $batchId,
                'queued_at' => $now,
            ]);

            $row->status = $status;
            $row->message = $message;
            if ($toVersion !== null) {
                $row->to_version = $toVersion;
            }
            if ($status === UpgradeStatus::Checking || ($row->started_at === null && $status !== UpgradeStatus::Queued)) {
                $row->started_at = $now;
            }

            if ($status === UpgradeStatus::Done) {
                // os_version is the freshly read version by now
                $row->to_version = $device->os_version ?? $row->to_version;
            } elseif ($device->os_version !== null) {
                // before the reboot os_version is still what it's running now, and it gets
                // refreshed from the router during checking, so keep the latest read
                $row->from_version = $device->os_version;
            }

            if (! $status->inProgress()) {
                $row->finished_at = $now;
            }

            $row->save();

            return $row;
        });
    }
}
