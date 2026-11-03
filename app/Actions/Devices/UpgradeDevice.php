<?php

namespace App\Actions\Devices;

use App\Actions\Upgrade\FetchRouterosPackage;
use App\Actions\Upgrade\RecordUpgradeStatus;
use App\Enums\DeviceStatus;
use App\Enums\PollMethod;
use App\Enums\UpgradeStatus;
use App\Models\Device;
use App\Services\RouterOs\RouterOsClient;
use App\Services\RouterOs\RouterOsClientException;
use App\Services\RouterOs\RouterOsConnection;
use App\Services\RouterOs\RouterOsTarget;
use App\Services\Upgrade\DeviceRebootWaiter;
use App\Services\Upgrade\RouterosReleases;
use App\Support\EngineLog;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Upgrade one RouterOS device's firmware, tracking the lifecycle on the device so
 * the UI can show a spinner -> result:
 *   checking -> (up_to_date)            ... already on the latest, nothing to do
 *   checking -> downloading -> rebooting -> done   ... pulled a newer release, rebooted, confirmed
 *   checking -> downloading -> rebooting -> failed ... came back on the old version (RouterOS
 *                                                     refused the install; its log says why)
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
    private RouterosReleases $releases;

    private FetchRouterosPackage $packages;

    public function __construct(
        private RouterOsClient $client,
        private DeviceRebootWaiter $waiter,
        ?RouterosReleases $releases = null,
        ?FetchRouterosPackage $packages = null,
    ) {
        $this->releases = $releases ?? new RouterosReleases;
        $this->packages = $packages ?? new FetchRouterosPackage($this->releases);
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

        $this->mark($device, UpgradeStatus::Checking, $version !== null ? "Preparing {$version}..." : 'Checking for updates...', $version);

        try {
            $expected = $version !== null
                ? $this->installTargeted($device, $version, $source)
                : $this->checkAndMaybeReboot($device);

            if ($expected === null) {
                return; // no reboot - up_to_date or failed already marked
            }

            if (! $this->waiter->awaitRecovery($device)) {
                $this->mark($device, UpgradeStatus::Failed, 'Did not come back online after reboot.');

                return;
            }

            // Confirm it really is on the new version. A router reboots happily when RouterOS
            // refuses the install (a missing extra package, no space) and comes back on the old
            // one, so a successful reboot proves nothing on its own.
            $running = $this->readVersionAfterReboot($device);
            if ($running === null) {
                $this->mark($device, UpgradeStatus::Failed, "Came back online, but its RouterOS version couldn't be read to confirm {$expected} installed.");

                return;
            }
            $device->os_version = $running;
            if ($running !== $expected) {
                $reason = $this->installFailureReason($device);
                $this->mark($device, UpgradeStatus::Failed, "Rebooted, but it's still on {$running}: RouterOS didn't install {$expected}"
                    .($reason !== null ? " ({$reason})." : '.'));
                EngineLog::warning('upgrade: rebooted but not upgraded', [
                    'device_id' => $device->id, 'device' => $device->name, 'running' => $running, 'expected' => $expected, 'reason' => $reason,
                ]);

                return;
            }

            $this->mark($device, UpgradeStatus::Done, "Upgraded to {$running}.");

            EngineLog::info('upgrade: complete', [
                'device_id' => $device->id, 'device' => $device->name, 'version' => $running,
            ]);
        } catch (Throwable $e) {
            $this->mark($device, UpgradeStatus::Failed, $e->getMessage()); // host/error only - never creds
            throw $e;
        }
    }

    /**
     * Upgrade to the latest in the device's channel. RouterOS's own updater downloads every
     * installed package, extras included. Returns the version it rebooted to apply, or null if
     * there was nothing to do.
     */
    private function checkAndMaybeReboot(Device $device): ?string
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

                return null;
            }

            $this->mark($device, UpgradeStatus::Downloading, "Downloading {$latest}...", $latest);
            $conn->query('/system/package/update/download');

            $this->mark($device, UpgradeStatus::Rebooting, "Rebooting to apply {$latest}...", $latest);
            $conn->query('/system/reboot');

            return $latest;
        } finally {
            $conn->close();
        }
    }

    /**
     * Install a specific version: fetch its per-arch .npk files onto the router and reboot. The
     * router pulls them straight from MikroTik (source 'mikrotik') or from our cache (source
     * 'mirror'); on reboot RouterOS installs any .npk in root.
     *
     * On RouterOS 7 that has to be every installed package, not just `routeros`: with an extra
     * such as wifi-qcom-ac installed and only routeros staged, RouterOS logs "missing package
     * wifi-qcom-ac", skips the install and boots the old version. So stage one .npk per installed
     * package and check each one landed. If any didn't, remove what was staged (a later reboot
     * would only hit the same wall) and don't reboot. Returns the version it rebooted to apply,
     * or null if it didn't reboot.
     */
    private function installTargeted(Device $device, string $version, string $source): ?string
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

                return null;
            }
            if ($installed !== null && self::normalizeVersion($version) === $installed) {
                $this->mark($device, UpgradeStatus::UpToDate, "Already on {$version}.");

                return null;
            }

            // RouterOS 6 ships the standard packages as one bundle, so `routeros` is all there is.
            $names = (int) $version >= 7 ? ['routeros', ...$this->extraPackages($conn)] : ['routeros'];

            $staged = [];
            foreach ($names as $name) {
                $filename = $this->releases->packageFilename($version, $arch, $name);

                if ($source === 'mirror') {
                    $pkg = $this->packages->fetch($version, $arch, null, $name);
                    if (! $pkg->isReady()) {
                        $this->removeFiles($conn, $staged);
                        $this->mark($device, UpgradeStatus::Failed, "Couldn't cache {$filename}: ".($pkg->error ?? 'download failed').' - not rebooting.');

                        return null;
                    }
                    $url = url('/rospkg/'.$pkg->token);
                } else {
                    $url = $this->releases->packageUrl($version, $arch, $name);
                }

                $this->mark($device, UpgradeStatus::Downloading, "Fetching {$filename} to the device...");
                $staged[] = $filename;
                try {
                    $conn->query('/tool/fetch', ['url' => $url, 'dst-path' => $filename, 'mode' => str_starts_with($url, 'https') ? 'https' : 'http']);
                } catch (RouterOsClientException) {
                    // A 404 (no such package for this version) or a dropped download. The file
                    // check below reports it.
                }

                if (! $this->fileOnDevice($conn, $filename)) {
                    $this->removeFiles($conn, $staged);
                    $this->mark($device, UpgradeStatus::Failed, "{$filename} didn't download onto {$device->name} - not rebooting.");

                    return null;
                }
            }

            $this->mark($device, UpgradeStatus::Rebooting, "Rebooting to apply {$version}...");
            $conn->query('/system/reboot');

            return self::normalizeVersion($version) ?? $version;
        } finally {
            $conn->close();
        }
    }

    /**
     * The extra packages installed on a RouterOS 7 router (wifi-qcom-ac, container, ...): every
     * package but `routeros` itself, skipping any already scheduled for uninstall.
     *
     * @return list<string>
     */
    private function extraPackages(RouterOsConnection $conn): array
    {
        $names = [];
        foreach ($conn->query('/system/package/print') as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || $name === 'routeros' || str_contains(strtolower((string) ($row['scheduled'] ?? '')), 'uninstall')) {
                continue;
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /** Best-effort removal of .npk files we staged, so an abandoned upgrade isn't applied on a later reboot. */
    private function removeFiles(RouterOsConnection $conn, array $names): void
    {
        foreach ($names as $name) {
            try {
                $conn->query('/file/remove', ['numbers' => $name]);
            } catch (Throwable) {
                // Not there (the fetch never wrote it) or the router refused - nothing more to do.
            }
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

    /**
     * The running version once the router is back. It answers ping before its API is up, so
     * give the API a few tries before giving up.
     */
    private function readVersionAfterReboot(Device $device): ?string
    {
        $attempts = max(1, (int) config('mymate.upgrade.version_check_attempts', 6));
        for ($i = 1; $i <= $attempts; $i++) {
            $version = $this->readVersion($device);
            if ($version !== null || $i === $attempts) {
                return $version;
            }
            sleep(max(0, (int) config('mymate.upgrade.poll_interval_s', 10)));
        }

        return null;
    }

    /**
     * Why RouterOS skipped the install, from its log ("missing package wifi-qcom-ac", "not
     * enough space for upgrade"). Null when the log doesn't say or can't be read.
     */
    private function installFailureReason(Device $device): ?string
    {
        try {
            $conn = $this->client->open(RouterOsTarget::fromDevice($device));
            try {
                $rows = $conn->query('/log/print');
            } finally {
                $conn->close();
            }
        } catch (Throwable) {
            return null;
        }

        $reasons = [];
        foreach (array_reverse($rows) as $row) {
            $message = trim((string) ($row['message'] ?? ''));
            if (preg_match('/missing package|not enough (disk )?space|(bad|invalid|broken|corrupt\w*) (package|npk)|(failed to|unable to|could not) install|install(ation)? failed|depend/i', $message) === 1) {
                $reasons[$message] = true;
            }
            if (count($reasons) === 3) {
                break;
            }
        }

        return $reasons === [] ? null : implode('; ', array_keys($reasons));
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

    /** Every state change goes through the recorder so the history row stays in step. */
    private function mark(Device $device, UpgradeStatus $status, ?string $message = null, ?string $toVersion = null): void
    {
        (new RecordUpgradeStatus)($device, $status, $message, $toVersion);
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
