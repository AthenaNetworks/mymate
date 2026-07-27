<?php

namespace App\Actions\Polling;

use App\Events\InterfaceUtilUpdated;
use App\Models\Device;
use App\Models\Link;
use App\Models\NetworkInterface;
use App\Support\EngineLog;
use App\Support\LiveBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a throughput tick for a *set* of devices (one batch / shard) - the
 * scale-out unit:
 *  - polls each device (failure of one is isolated, never sinks the batch),
 *  - persists every interface in **one bulk upsert** (not one save per interface),
 *  - **coalesces** the broadcast into as few `InterfaceUtilUpdated` events as the
 *    per-event interface *and byte* caps allow ( - a tick can't flood the
 *    WS layer, and can't exceed Reverb's message-size limit either).
 *
 * Returns the number of devices that produced data.
 */
class PollInterfaces
{
    public function __construct(private PollDeviceInterfaces $pollDevice) {}

    /** @param  list<int>  $deviceIds */
    public function __invoke(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        $startedAt = microtime(true);
        $now = now()->format('Y-m-d H:i:s');
        $devices = Device::with('credential')->whereIn('id', $deviceIds)->get();

        $rows = [];
        $deviceFrames = [];
        $sampleRows = [];
        $failed = 0;

        foreach ($devices as $device) {
            try {
                $result = ($this->pollDevice)($device);
            } catch (\Throwable $e) {
                // One black-holing/erroring device must not take the batch down.
                // (Driver exceptions carry host + transport error only, never creds.)
                $failed++;
                EngineLog::warning('poll: device poll failed', [
                    'device_id' => $device->id,
                    'device' => $device->name,
                    'ip' => $device->mgmt_ip,
                    'method' => $device->poll_method->value,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result === null) {
                continue;
            }

            array_push($rows, ...$result->upsertRows);
            $deviceFrames[] = $result->frame();

            // Recent history: one sample per interface that produced real
            // data. Throughput (bps) is a valid reading even with no known speed (util
            // null) - so record on any non-null util OR bps, and skip only fully-empty
            // baseline/counter-reset ticks (all four null) so graphs don't show fake zeroes.
            foreach ($result->frames as $f) {
                if ($f['util_in'] === null && $f['util_out'] === null && $f['bps_in'] === null && $f['bps_out'] === null) {
                    continue;
                }
                $sampleRows[] = [
                    'interface_id' => $f['interface_id'],
                    'ts' => $now,
                    'bps_in' => $f['bps_in'],
                    'bps_out' => $f['bps_out'],
                    'util_in' => $f['util_in'],
                    'util_out' => $f['util_out'],
                ];
            }
        }

        if ($rows !== []) {
            NetworkInterface::upsert(
                $rows,
                ['device_id', 'if_index'],
                ['last_in', 'last_out', 'last_ts', 'util_in', 'util_out', 'bps_in', 'bps_out', 'oper_status', 'updated_at'],
            );
        }

        $this->recordHistory($sampleRows);
        $broadcastBytes = $this->broadcast($deviceFrames);

        // Heartbeat (debug): one line per batch so cadence + failure rate are visible.
        // `broadcast_bytes` makes payload-size trend visible on every tick -
        // the original "Payload too large" bug had no such signal and ran silently for
        // two days before anyone checked the logs for an unrelated reason.
        EngineLog::debug('poll: batch complete', [
            'devices' => $devices->count(),
            'polled' => count($deviceFrames),
            'failed' => $failed,
            'interfaces' => count($rows),
            'samples' => count($sampleRows),
            'broadcast_bytes' => $broadcastBytes,
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return count($deviceFrames);
    }

    /**
     * Bulk-append history samples - one insert for the whole batch, off
     * the live path. Best-effort: a DB hiccup or a momentarily-missing partition
     * must never break a poll tick (it just loses that tick's history).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function recordHistory(array $rows): void
    {
        if ($rows === [] || ! config('mymate.history.enabled', true)) {
            return;
        }

        try {
            DB::table('interface_samples')->insert($rows);
        } catch (\Throwable $e) {
            EngineLog::warning('history: sample write failed', [
                'rows' => count($rows),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit coalesced util events, each bounded by *two* independent caps - interface
     * count and serialised bytes - whichever is hit first ends the current
     * chunk. The count cap alone (`max_interfaces_per_event`) is a cheap sanity bound,
     * not real protection: at ~166 bytes/interface (measured live), 500 interfaces is
     * ~83,000 bytes - 8x over Reverb's 10,000-byte default message-size limit. The byte
     * cap (`max_bytes_per_event`) is what actually keeps a chunk under that limit,
     * regardless of how many links get added later. Narrowed to link-bound interfaces
     * first (see {@see narrowToLinkedInterfaces}) - only those are ever rendered live
     * (`UtilEdge` colours edges from a link's two interface ids - nothing reads a
     * non-link interface's *live* util, only its last-polled value via the REST API).
     *
     * A single device's frame is never split across events (a soft bound, same as
     * before) - but if it alone exceeds the byte budget, sending it would just be
     * rejected by Reverb the same way (and could take any co-chunked devices down
     * with it), so it's skipped for the live broadcast - persistence/history above
     * are unaffected - with a warning logged instead of silently failing.
     *
     * @param  list<array{device_id:int, status:string, interfaces:list<array<string,mixed>>}>  $deviceFrames
     * @return int total bytes actually broadcast (for the batch-complete heartbeat)
     */
    // Generous fixed estimate for `InterfaceUtilUpdated::broadcastWith()`'s outer
    // `{"devices":[...],"device_count":N,"interface_count":M}` wrapper - the cap check
    // below sums *only* each device frame's own bytes, so this keeps the guarantee
    // accurate against the real dispatched payload rather than undercounting it.
    private const WRAPPER_OVERHEAD_BYTES = 64;

    private function broadcast(array $deviceFrames): int
    {
        if ($deviceFrames === [] || ! config('mymate.poll.broadcast.enabled', true)) {
            return 0;
        }

        $deviceFrames = $this->narrowToLinkedInterfaces($deviceFrames);
        if ($deviceFrames === []) {
            return 0;
        }

        $capCount = max(1, (int) config('mymate.poll.broadcast.max_interfaces_per_event', 500));
        $capBytes = max(1, (int) config('mymate.poll.broadcast.max_bytes_per_event', 6000));

        $chunk = [];
        $chunkCount = 0;
        $chunkBytes = 0;
        $totalBytes = 0;

        foreach ($deviceFrames as $frame) {
            $n = count($frame['interfaces']);
            $bytes = strlen(json_encode($frame));

            if ($bytes + self::WRAPPER_OVERHEAD_BYTES > $capBytes) {
                EngineLog::warning('poll: device broadcast frame exceeds byte budget', [
                    'device_id' => $frame['device_id'],
                    'interfaces' => $n,
                    'bytes' => $bytes,
                    'budget' => $capBytes,
                ]);

                continue;
            }

            if ($chunk !== [] && ($chunkCount + $n > $capCount || $chunkBytes + $bytes + self::WRAPPER_OVERHEAD_BYTES > $capBytes)) {
                LiveBroadcast::send(new InterfaceUtilUpdated($chunk));
                $totalBytes += $chunkBytes + self::WRAPPER_OVERHEAD_BYTES;
                $chunk = [];
                $chunkCount = 0;
                $chunkBytes = 0;
            }

            $chunk[] = $frame;
            $chunkCount += $n;
            $chunkBytes += $bytes;
        }

        if ($chunk !== []) {
            LiveBroadcast::send(new InterfaceUtilUpdated($chunk));
            $totalBytes += $chunkBytes + self::WRAPPER_OVERHEAD_BYTES;
        }

        return $totalBytes;
    }

    /**
     * Drop every interface that isn't one end of a `Link`, and any device left with
     * none - the live broadcast only ever needs to carry what the map can actually
     * colour. Persistence/history above are unaffected (those keep every interface);
     * this narrowing is for the WS payload alone.
     *
     * @param  list<array{device_id:int, status:string, interfaces:list<array<string,mixed>>}>  $deviceFrames
     * @return list<array{device_id:int, status:string, interfaces:list<array<string,mixed>>}>
     */
    private function narrowToLinkedInterfaces(array $deviceFrames): array
    {
        $linkedIds = [];
        foreach (Link::query()->select('a_interface_id', 'b_interface_id')->get() as $link) {
            $linkedIds[$link->a_interface_id] = true;
            $linkedIds[$link->b_interface_id] = true;
        }

        if ($linkedIds === []) {
            return [];
        }

        $out = [];
        foreach ($deviceFrames as $frame) {
            $interfaces = array_values(array_filter(
                $frame['interfaces'],
                static fn (array $f): bool => isset($linkedIds[$f['interface_id']]),
            ));
            if ($interfaces === []) {
                continue;
            }
            $out[] = [...$frame, 'interfaces' => $interfaces];
        }

        return $out;
    }
}
