<?php

namespace App\Actions\Cmr;

use App\Enums\DiscoveryStatus;
use App\Models\Device;
use App\Models\DiscoveryCandidate;
use App\Services\Cmr\CmrClient;
use Carbon\CarbonImmutable;

/**
 * Pull the CMR controller's device list into My Mate. A device already in inventory gets its
 * cmr_* mirror refreshed (connected, last seen, source IP); anything else is offered in the
 * discovery review queue, keyed by its CMR device id, for an admin to approve.
 *
 * Pairing is open on the daemon side, so nothing here ever creates a device by itself. An ignored
 * candidate stays ignored however often the device re-pairs; an approved one whose device has since
 * been deleted goes back to `new` so it can be added again.
 */
class SyncCmrDevices
{
    public function __construct(private CmrClient $client) {}

    /** @return array{devices:int, candidates:int} */
    public function __invoke(): array
    {
        $rows = $this->client->devices();
        $seen = [];
        $devices = 0;
        $candidates = 0;

        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $seen[] = $id;
            $mirror = $this->mirror($row);

            $device = Device::withoutGlobalScope('visibility')->where('cmr_device_id', $id)->first();
            if ($device !== null) {
                $device->update($mirror);
                $devices++;

                continue;
            }

            // A record the daemon holds before the device ever connected (pre-enrolled config) has
            // no address to add it at yet. It turns up here once the device dials in.
            if ($mirror['cmr_remote_addr'] === null) {
                continue;
            }

            $candidate = DiscoveryCandidate::firstOrNew(['cmr_device_id' => $id]);
            if ($candidate->status === DiscoveryStatus::Approved) {
                $candidate->status = DiscoveryStatus::New;
            }
            $candidate->fill([
                'source' => DiscoveryCandidate::SOURCE_CMR,
                'ip' => $mirror['cmr_remote_addr'],
                'agent_id' => null,
                'sysname' => $this->str($row['identity'] ?? null),
                'mac' => $mirror['cmr_mac'],
                'board' => $mirror['cmr_board'],
                'os' => $mirror['cmr_os'],
                'first_seen' => $candidate->first_seen ?? $mirror['cmr_first_seen'] ?? now(),
                'last_seen' => $mirror['cmr_last_seen'] ?? now(),
            ])->save();
            $candidates++;
        }

        // Forgotten by the daemon (or its registry was reset): no longer connected through it.
        Device::withoutGlobalScope('visibility')
            ->whereNotNull('cmr_device_id')
            ->whereNotIn('cmr_device_id', $seen)
            ->where('cmr_connected', true)
            ->update(['cmr_connected' => false]);

        return ['devices' => $devices, 'candidates' => $candidates];
    }

    /**
     * The cmr_* columns for one admin-API device row.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function mirror(array $row): array
    {
        return [
            'cmr_mac' => $this->str($row['mac'] ?? null, 32),
            'cmr_board' => $this->str($row['board'] ?? null),
            'cmr_os' => $this->str($row['os'] ?? null),
            'cmr_connected' => (bool) ($row['connected'] ?? false),
            'cmr_remote_addr' => $this->ip($row['last_remote_addr'] ?? null),
            'cmr_first_seen' => $this->time($row['first_seen'] ?? null),
            'cmr_last_seen' => $this->time($row['last_seen'] ?? null),
        ];
    }

    private function str(mixed $v, int $max = 255): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** Only a real IP address is kept: it becomes a management IP on approve. */
    private function ip(mixed $v): ?string
    {
        $s = trim((string) $v);

        return filter_var($s, FILTER_VALIDATE_IP) !== false ? $s : null;
    }

    /** Go's RFC 3339 time, nanoseconds and all; its zero value (year 1) means "never". */
    private function time(mixed $v): ?CarbonImmutable
    {
        $s = (string) $v;
        if ($s === '' || str_starts_with($s, '0001-')) {
            return null;
        }
        // PHP parses at most microseconds, so trim any longer fraction first.
        $s = (string) preg_replace('/(\.\d{6})\d+/', '$1', $s);

        try {
            return CarbonImmutable::parse($s)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
