<?php

namespace App\Actions\Discovery;

use App\Actions\Devices\CreateDevice;
use App\Enums\DiscoveryStatus;
use App\Enums\PollMethod;
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
            if ($candidate->isCmr()) {
                return $this->promoteCmr($candidate);
            }

            // Same poll scope as the candidate: an agent-found host is looked up (and created)
            // behind that agent, so a same-IP device at another site is left alone (GitHub #49).
            $agentId = $candidate->agent_id;
            $device = Device::withoutGlobalScope('visibility')->inPollScope($agentId)->where('mgmt_ip', $candidate->ip)->first();

            if ($device === null) {
                // Discovery only queues hosts it matched at least one credential to. A candidate
                // with no poll method AND no SSH credential was never identified and has nothing
                // to link, so it must not become a device.
                if ($candidate->detected_method === null && $candidate->matched_ssh_credential_id === null) {
                    throw new \RuntimeException(
                        "Cannot promote discovery candidate {$candidate->ip}: it was never identified via SNMP, RouterOS or SSH."
                    );
                }

                $attributes = [
                    'name' => $candidate->sysname ?: $candidate->ip,
                    'mgmt_ip' => $candidate->ip,
                    // Polled by the agent that found it - the central server usually has no route there.
                    'agent_id' => $agentId,
                    // SSH-only match -> a ping-only device we can still back up over SSH.
                    'poll_method' => $candidate->detected_method ?? PollMethod::None,
                    'credential_id' => $candidate->matched_credential_id,
                    'ssh_credential_id' => $candidate->matched_ssh_credential_id,
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
                    $device = Device::withoutGlobalScope('visibility')->inPollScope($agentId)->where('mgmt_ip', $candidate->ip)->firstOrFail();
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

    /**
     * A device paired with the CMR controller. It's matched on its CMR device id first, then on
     * its address (the device may already be monitored by IP, and now gets linked to CMR). It's
     * added ping-only: CMR pairing needs no credential, and the operator assigns one (or fixes
     * the address of a device behind NAT) in the inspector like any other device.
     */
    private function promoteCmr(DiscoveryCandidate $candidate): Device
    {
        $cmr = [
            'cmr_device_id' => $candidate->cmr_device_id,
            'cmr_mac' => $candidate->mac,
            'cmr_board' => $candidate->board,
            'cmr_os' => $candidate->os,
            'cmr_remote_addr' => $candidate->ip,
            'cmr_first_seen' => $candidate->first_seen,
            'cmr_last_seen' => $candidate->last_seen,
        ];

        $devices = Device::withoutGlobalScope('visibility');
        $device = (clone $devices)->where('cmr_device_id', $candidate->cmr_device_id)->first()
            ?? (clone $devices)->inPollScope(null)->where('mgmt_ip', $candidate->ip)->first();

        if ($device !== null && $device->cmr_device_id !== null && $device->cmr_device_id !== $candidate->cmr_device_id) {
            // Two CMR devices behind one NAT address: the first one took the IP.
            throw new CmrAddressTaken(
                "{$device->name} already uses {$candidate->ip}. If these devices are behind NAT, give that one its real management IP first."
            );
        }

        if ($device === null) {
            $device = ($this->createDevice)([
                'name' => $candidate->sysname ?: ($candidate->mac ?: $candidate->ip),
                'mgmt_ip' => $candidate->ip,
                'poll_method' => PollMethod::None,
                'model' => $candidate->board,
            ] + $cmr);
        } else {
            $device->update($cmr);
        }

        $candidate->update(['status' => DiscoveryStatus::Approved]);

        return $device;
    }
}
