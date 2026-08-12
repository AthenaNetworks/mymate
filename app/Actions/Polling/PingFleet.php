<?php

namespace App\Actions\Polling;

use App\Actions\Outages\RecordOutage;
use App\Enums\DeviceStatus;
use App\Events\DeviceLatencyUpdated;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Services\Ping\Pinger;
use App\Services\Ping\PingSample;
use App\Support\EngineLog;
use App\Support\LiveBroadcast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One up/down sweep: ping every device's mgmt_ip in a single batch, then flip status only
 * for devices whose reachability changed (stamping last_change and broadcasting). The same
 * sweep also carries latency/loss, which is persisted on a slower cadence (see recordLatency)
 * so the fast status path stays cheap. Returns the number of devices that changed.
 */
class PingFleet
{
    public function __construct(private Pinger $pinger, private RecordOutage $outages) {}

    /**
     * @param  list<int>|null  $deviceIds  ping only this slice (one shard); null = the whole
     *                                      fleet in a single sweep (small installs / the default).
     *                                      A single fping over a very large fleet blows past the
     *                                      process timeout, so PingDispatcher shards the fleet and
     *                                      calls this once per shard - each sweep stays small and
     *                                      the shards run in parallel across the ping workers.
     */
    public function __invoke(?array $deviceIds = null): int
    {
        // Skip monitoring-paused devices (monitored=false -> mock/demo) and agent-assigned
        // devices (agent_id set -> pinged by their remote agent, not from here).
        $devices = Device::where('monitored', true)->whereNull('agent_id')
            ->when($deviceIds !== null, fn ($q) => $q->whereIn('id', $deviceIds))
            ->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        /** @var array<string, PingSample> $samples */
        $samples = $this->pinger->measure($devices->pluck('mgmt_ip')->all());

        // Flap dampening: a device only flips to `down` after `fail_threshold` consecutive
        // missed sweeps, so a single dropped reply (transient loss, a busy sweep) doesn't alarm.
        // Recovery is immediate - one reply resets the streak and marks it up.
        $threshold = max(1, (int) config('mymate.ping.fail_threshold', 3));

        $changed = 0;
        foreach ($devices as $device) {
            $reachable = ($samples[$device->mgmt_ip] ?? null)?->reachable ?? false;

            $streak = (int) $device->fail_streak;
            if ($reachable) {
                $newStreak = 0;
                $newStatus = DeviceStatus::Up;
            } else {
                $newStreak = min($streak + 1, $threshold); // cap so a long-down device isn't rewritten
                // Hold the current status until we've missed `threshold` sweeps in a row.
                $newStatus = $newStreak >= $threshold ? DeviceStatus::Down : $device->status;
            }

            $streakChanged = $newStreak !== $streak;
            $statusChanged = $device->status !== $newStatus;
            if (! $streakChanged && ! $statusChanged) {
                continue;
            }

            $device->fail_streak = $newStreak;
            if ($statusChanged) {
                $device->status = $newStatus;
                $device->last_change = now();
            }
            $device->save();

            if ($statusChanged) {
                // Log the outage window: open on down, close on recovery.
                $newStatus === DeviceStatus::Down ? $this->outages->open($device) : $this->outages->close($device);

                LiveBroadcast::send(new DeviceStatusChanged($device));
                $changed++;
            }
        }

        $this->recordLatency($devices, $samples);

        EngineLog::debug('ping: sweep complete', [
            'total' => $devices->count(),
            'reachable' => count(array_filter($samples, static fn (PingSample $s): bool => $s->reachable)),
            'changed' => $changed,
        ]);

        return $changed;
    }

    /**
     * Persist a latency/loss trend sample and refresh the live rtt/loss columns. Throttled PER
     * DEVICE to `ping.history_interval` (off each device's own last write, `ping_at`) so it runs
     * about once a minute rather than every few-second sweep - the up/down flip above still happens
     * every sweep. Keying the throttle on the device rather than the swept set keeps the trend
     * cadence steady no matter how the fleet was sharded, or which per-map ping bucket (GitHub #32)
     * a device fell in this tick - a device on a 5s map still records latency at the history
     * cadence, not on every sweep, and every device gets its samples regardless of shard.
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, PingSample>  $samples
     */
    private function recordLatency(Collection $devices, array $samples): void
    {
        $interval = max(5, (int) config('mymate.ping.history_interval', 60));
        $now = now();
        $cutoff = $now->copy()->subSeconds($interval);

        $rows = [];
        $frames = []; // live rtt/loss for the internet card
        foreach ($devices as $device) {
            // One trend point per interval per device - skip a device that recorded within the
            // window. A never-recorded device (ping_at null) always writes on the first sweep.
            if ($device->ping_at !== null && $device->ping_at->greaterThan($cutoff)) {
                continue;
            }
            $s = $samples[$device->mgmt_ip] ?? null;
            if ($s === null) {
                continue;
            }
            $device->forceFill(['rtt_ms' => $s->rttMs, 'loss_pct' => $s->lossPct, 'ping_at' => $now])->save();
            $rows[] = [
                'device_id' => $device->id,
                'ts' => $now,
                'rtt_ms' => $s->rttMs,
                'loss_pct' => $s->lossPct,
                'jitter_ms' => $s->jitterMs,
            ];
            $frames[] = [
                'device_id' => $device->id,
                'rtt_ms' => $s->rttMs,
                'loss_pct' => $s->lossPct,
            ];
        }

        if ($rows !== []) {
            DB::table('ping_samples')->insert($rows);
        }

        if ($frames !== [] && config('mymate.device_metrics.broadcast', true)) {
            LiveBroadcast::send(new DeviceLatencyUpdated($frames));
        }
    }
}
