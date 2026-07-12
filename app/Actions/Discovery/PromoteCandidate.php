<?php

namespace App\Actions\Discovery;

use App\Actions\Devices\CreateDevice;
use App\Enums\DiscoveryStatus;
use App\Jobs\DiscoverInterfacesJob;
use App\Jobs\PollInterfacesJob;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Approve a discovery candidate -> create the real `device` (carrying the matched
 * credential + detected poll_method) and kick a first discovery + poll so it
 * populates immediately. Marks the candidate `approved` so a re-scan won't re-offer
 * it (and its IP is now a device, which the scanner skips anyway).
 *
 * Idempotent on the device: if a device already exists at the candidate's IP we
 * reuse it rather than creating a duplicate. The "does a device exist?" check
 * below is inherently a check-then-act race under READ COMMITTED (a double-click
 * "Approve" could see no device twice before either commits) - the real guarantee
 * is the DB-level unique index on `devices.mgmt_ip` (migration
 * `2026_07_01_000003`, found via adversarial review); the loser's insert fails and
 * is caught here, re-fetching the winner's row instead of erroring.
 */
class PromoteCandidate
{
    public function __construct(private CreateDevice $createDevice) {}

    public function __invoke(DiscoveryCandidate $candidate): Device
    {
        $device = DB::transaction(function () use ($candidate): Device {
            $device = Device::where('mgmt_ip', $candidate->ip)->first();

            if ($device === null) {
                // Discovery only queues hosts it positively identified (#7), so a real
                // poll_method + credential are always present here. Guard defensively:
                // an unidentified candidate has nothing to poll and must not become a device.
                if ($candidate->detected_method === null) {
                    throw new \RuntimeException(
                        "Cannot promote discovery candidate {$candidate->ip}: it was never identified via SNMP or RouterOS."
                    );
                }

                $attributes = [
                    'name' => $candidate->sysname ?: $candidate->ip,
                    'mgmt_ip' => $candidate->ip,
                    'poll_method' => $candidate->detected_method,
                    'credential_id' => $candidate->matched_credential_id,
                ];

                try {
                    // A nested DB::transaction() becomes a SAVEPOINT - a unique-violation
                    // here only rolls back the insert attempt, not the outer transaction's
                    // candidate-status update below.
                    $device = DB::transaction(fn (): Device => ($this->createDevice)($attributes));
                } catch (QueryException $e) {
                    // getCode() is a string SQLSTATE ('23505') from a real PDO/Postgres
                    // error, but PHP's base Exception coerces a numeric code to int -
                    // compare as strings so either form is recognised.
                    if ((string) $e->getCode() !== '23505') { // Postgres unique_violation
                        throw $e;
                    }
                    // Another request won the race and already created it - reuse it.
                    $device = Device::where('mgmt_ip', $candidate->ip)->firstOrFail();
                }
            }

            $candidate->update(['status' => DiscoveryStatus::Approved]);

            return $device;
        });

        // After commit: populate the new device right away (interfaces, then a first tick).
        DiscoverInterfacesJob::dispatch($device->id);
        PollInterfacesJob::dispatch($device->id);

        return $device;
    }
}
