<?php

namespace App\Actions\Devices;

use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Upgrade\DeviceRebootWaiter;
use App\Support\EngineLog;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Upgrade one RouterOS device's firmware, tracking the lifecycle on the device so
 * the UI can show a spinner -> result:
 *   checking -> (up_to_date)            ... already on the latest, nothing to do
 *   checking -> downloading -> rebooting -> done   ... pulled a newer release, rebooted, confirmed
 *
 * Reboots are destructive, so callers gate it behind a confirm + run it on the
 * isolated `upgrade` queue. After issuing the reboot this **waits for the device to
 * come back** (so ordered bulk upgrades can rely on it) and then re-reads the live
 * version. Never logs credentials.
 *
 * **Concurrency (found via adversarial review, 2026-07-01):** `UpgradeDeviceJob`'s
 * `WithoutOverlapping` lock is scoped per-job-class, so it never coordinates with
 * `RunBulkUpgrade` (which calls this Action directly, bypassing the job entirely).
 * A manual single-device upgrade and a bulk upgrade selecting the same device could
 * both issue `/system/reboot` concurrently. The lock below lives in the Action
 * itself so **every** call path is serialized per device, regardless of entry point.
 */
class UpgradeDevice
{
    private \App\Services\Upgrade\RouterosReleases $releases;

    private \App\Actions\Upgrade\FetchRouterosPackage $packages;

    public function __construct(
        private RouterOsClient $client,
        private DeviceRebootWaiter $waiter,
        ?\App\Services\Upgrade\RouterosReleases $releases = null,
        ?\App\Actions\Upgrade\FetchRouterosPackage $packages = null,
    ) {
        $this->releases = $releases ?? new \App\Services\Upgrade\RouterosReleases;
        $this->packages = $packages ?? new \App\Actions\Upgrade\FetchRouterosPackage($this->releases);
    }

    /**
     * @param  ?string  $version  target RouterOS version; null = latest in the device's channel
     * @param  string  $source  'mikrotik' (router fetches from MikroTik) or 'mirror' (from us)
     */
    public function __invoke(Device $device, ?string $version = null, string $source = 'mikrotik'): void
    {
        $lock = Cache::lock("device-upgrade-inflight:{$device->id}", 900);
        if (! $lock->get()) {
            throw new RuntimeException(
                "Device {$device->id} ({$device->name}) already has an upgrade in progress - refusing to start a second one concurrently."
            );
        }

        try {
            $this->run($device, $version, $source);
        } finally {
            $lock->release();
        }
    }

    private function run(Device $device, ?string $version = null, string $source = 'mikrotik'): void
    {
        if ($device->poll_method !== PollMethod::RouterOs) {
            $this->mark($device, UpgradeStatus::Failed, 'Not a RouterOS device - cannot upgrade.');
            throw new RouterOsClientException("Device {$device->id} ({$device->name}) is not a RouterOS device - cannot upgrade.");
        }

        // Dependency/safety pre-checks: don't upgrade an offline device,
        // and don't upgrade one whose parent is down (the path to it is broken). These
        // skip cleanly (no throw) so a bulk run keeps going.
        if ($device->status === DeviceStatus::Down) {
            $this->mark($device, UpgradeStatus::Failed, 'Device is down - skipped (not upgraded).');

            return;
        }

        $parent = $device->loadMissing('parent')->parent;
        if ($parent !== null && $parent->status === DeviceStatus::Down) {
            $this->mark($device, UpgradeStatus::Failed, "Parent {$parent->name} is down - skipped (path unreachable).");

            return;
        }

        $this->mark($device, UpgradeStatus::Checking, $version !== null ? "Preparing {$version}..." : 'Checking for updates...');

        try {
            $rebooted = $version !== null
                ? $this->installTargeted($device, $version, $source)
                : $this->checkAndMaybeReboot($device);

            if (! $rebooted) {
                return; // up_to_date already marked
            }

            if (! $this->waiter->awaitRecovery($device)) {
                $this->mark($device, UpgradeStatus::Failed, 'Did not come back online after reboot.');

                return;
            }

            // Confirm the new version (best-effort; fall back to the target version).
            $version = $this->readVersion($device) ?? $device->latest_version;
            $device->os_version = $version;
            $this->mark($device, UpgradeStatus::Done, 'Upgraded to '.($version ?? 'the latest release').'.');

            EngineLog::info('upgrade: complete', [
                'device_id' => $device->id, 'device' => $device->name, 'version' => $version,
            ]);
        } catch (Throwable $e) {
            $this->mark($device, UpgradeStatus::Failed, $e->getMessage()); // host/error only - never creds
            throw $e;
        }
    }

    /** Returns true if a reboot was issued (an update was available). */
    private function checkAndMaybeReboot(Device $device): bool
    {
        $conn = $this->client->open(RouterOsTarget::fromDevice($device));

        try {
            $conn->query('/system/package/update/check-for-updates');
            $u = $conn->query('/system/package/update/print')[0] ?? [];

            $installed = self::normalizeVersion((string) ($u['installed-version'] ?? ''));
            $latest = self::normalizeVersion((string) ($u['latest-version'] ?? ''));

            $device->os_version = $installed ?? $device->os_version;
            $device->latest_version = $latest ?? $device->latest_version;
            $device->save();

            // Only upgrade when the latest is *strictly newer* - never reboot for an
            // equal (or, defensively, older) reported version.
            if (! self::isNewer($latest, $installed)) {
                $this->mark($device, UpgradeStatus::UpToDate, 'Already on the latest'.($installed ? " ({$installed})." : '.'));

                return false;
            }

            $this->mark($device, UpgradeStatus::Downloading, "Downloading {$latest}...");
            $conn->query('/system/package/update/download');

            $this->mark($device, UpgradeStatus::Rebooting, "Rebooting to apply {$latest}...");
            $conn->query('/system/reboot');

            return true;
        } finally {
            $conn->close();
        }
    }

    /**
     * Install a specific version: fetch its per-arch .npk onto the router and reboot. The
     * router pulls the package straight from MikroTik (source 'mikrotik') or from our cache
     * (source 'mirror'); on reboot RouterOS installs any .npk in root. We verify the package
     * landed before rebooting - never reboot on a failed fetch. Returns true if a reboot issued.
     */
    private function installTargeted(Device $device, string $version, string $source): bool
    {
        $conn = $this->client->open(RouterOsTarget::fromDevice($device));

        try {
            $res = $conn->query('/system/resource/print')[0] ?? [];
            $arch = ($device->arch !== null && $device->arch !== '') ? $device->arch : trim((string) ($res['architecture-name'] ?? ''));
            $installed = self::normalizeVersion((string) ($res['version'] ?? ''));
            $device->os_version = $installed ?? $device->os_version;
            $device->latest_version = $version;
            $device->save();

            if ($arch === '') {
                $this->mark($device, UpgradeStatus::Failed, 'Could not determine the device architecture.');

                return false;
            }
            if ($installed !== null && self::normalizeVersion($version) === $installed) {
                $this->mark($device, UpgradeStatus::UpToDate, "Already on {$version}.");

                return false;
            }

            $filename = $this->releases->packageFilename($version, $arch);

            if ($source === 'mirror') {
                $pkg = $this->packages->fetch($version, $arch);
                if (! $pkg->isReady()) {
                    $this->mark($device, UpgradeStatus::Failed, "Couldn't cache {$version}/{$arch}: ".($pkg->error ?? 'download failed').'.');

                    return false;
                }
                $url = url('/rospkg/'.$pkg->token);
            } else {
                $url = $this->releases->packageUrl($version, $arch);
            }

            $this->mark($device, UpgradeStatus::Downloading, "Fetching {$version} ({$arch}) to the device...");
            $mode = str_starts_with($url, 'https') ? 'https' : 'http';
            $conn->query('/tool/fetch', ['url' => $url, 'dst-path' => $filename, 'mode' => $mode]);

            if (! $this->fileOnDevice($conn, $filename)) {
                $this->mark($device, UpgradeStatus::Failed, "The package didn't download onto {$device->name} - not rebooting.");

                return false;
            }

            $this->mark($device, UpgradeStatus::Rebooting, "Rebooting to apply {$version}...");
            $conn->query('/system/reboot');

            return true;
        } finally {
            $conn->close();
        }
    }

    /** Is a >1MB file with this name present on the router (the fetched .npk)? */
    private function fileOnDevice(RouterOsConnection $conn, string $name): bool
    {
        try {
            $rows = $conn->query('/file/print');
        } catch (Throwable) {
            return false;
        }
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $name && (int) ($row['size'] ?? 0) > 1_000_000) {
                return true;
            }
        }

        return false;
    }

    /** Read the live RouterOS version after a reboot; null if the device isn't answering yet. */
    private function readVersion(Device $device): ?string
    {
        try {
            $conn = $this->client->open(RouterOsTarget::fromDevice($device));
            try {
                $res = $conn->query('/system/resource/print')[0] ?? [];

                return self::normalizeVersion((string) ($res['version'] ?? ''));
            } finally {
                $conn->close();
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function mark(Device $device, UpgradeStatus $status, ?string $message = null): void
    {
        $device->upgrade_status = $status;
        $device->upgrade_message = $message;
        $device->upgrade_at = now();
        $device->save();
    }

    /** "7.20.8 (stable)" / "7.20.8" -> "7.20.8"; null when nothing version-like is present. */
    public static function normalizeVersion(string $raw): ?string
    {
        if (preg_match('/\d+\.\d+(\.\d+)?/', trim($raw), $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * True if $latest is a **strictly newer** version than $installed (both normalised).
     * Null latest -> not newer (nothing to do); null installed but a real latest ->
     * treat as newer (we have no baseline, so attempt the update).
     */
    public static function isNewer(?string $latest, ?string $installed): bool
    {
        $l = $latest !== null ? self::normalizeVersion($latest) : null;
        if ($l === null) {
            return false;
        }
        $i = $installed !== null ? self::normalizeVersion($installed) : null;
        if ($i === null) {
            return true;
        }

        return version_compare($l, $i, '>');
    }
}
