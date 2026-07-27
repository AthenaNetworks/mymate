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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One up/down sweep: ping every device's mgmt_ip in a single batch, then flip status only
 * for devices whose reachability changed (stamping last_change and broadcasting). The same
 * sweep also carries latency/loss, which is persisted on a slower cadence (see recordLatency)
 * so the fast status path stays cheap. Returns the number of devices that changed.
 */
class PingFleet
{
    /** Cache key: unix time of the last latency-history write (throttles the trend write). */
    private const LATENCY_KEY = 'mymate.ping.last_latency_write';

    public function __construct(private Pinger $pinger, private RecordOutage $outages) {}

    public function __invoke(): int
    {
        // Skip monitoring-paused devices (monitored=false -> mock/demo) and agent-assigned
        // devices (agent_id set -> pinged by their remote agent, not from here).
        $devices = Device::where('monitored', true)->whereNull('agent_id')->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        /** @var array<string, PingSample> $samples */
        $samples = $this->pinger->measure($devices->pluck('mgmt_ip')->all());

        $changed = 0;
        foreach ($devices as $device) {
            $new = ($samples[$device->mgmt_ip] ?? null)?->reachable ? DeviceStatus::Up : DeviceStatus::Down;

            if ($device->status !== $new) {
                $device->status = $new;
                $device->last_change = now();
                $device->save();

                // Log the outage window: open on down, close on recovery.
                $new === DeviceStatus::Down ? $this->outages->open($device) : $this->outages->close($device);

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
     * Persist a latency/loss trend sample and refresh the live rtt/loss columns. Throttled to
     * `ping.history_interval` so it runs about once a minute rather than every few-second sweep
     * (the up/down flip above still happens every sweep).
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, PingSample>  $samples
     */
    private function recordLatency(Collection $devices, array $samples): void
    {
        $interval = max(5, (int) config('mymate.ping.history_interval', 60));
        $last = (int) Cache::get(self::LATENCY_KEY, 0);
        if (now()->timestamp - $last < $interval) {
            return;
        }
        Cache::put(self::LATENCY_KEY, now()->timestamp, now()->addHour());

        $ts = now();
        $rows = [];
        $frames = []; // live rtt/loss for the internet card
        foreach ($devices as $device) {
            $s = $samples[$device->mgmt_ip] ?? null;
            if ($s === null) {
                continue;
            }
            $device->forceFill(['rtt_ms' => $s->rttMs, 'loss_pct' => $s->lossPct, 'ping_at' => $ts])->save();
            $rows[] = [
                'device_id' => $device->id,
                'ts' => $ts,
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
