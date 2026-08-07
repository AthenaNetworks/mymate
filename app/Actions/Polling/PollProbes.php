<?php

namespace App\Actions\Polling;

use App\Enums\DeviceStatus;
use App\Models\Probe;
use App\Services\Probes\ProbeRunner;
use App\Support\EngineLog;
use Illuminate\Support\Facades\DB;

/**
 * Run the due service probes (GitHub #19) for one shard of devices: execute each, apply flap
 * dampening (a probe only flips to down after N consecutive failures, like the ping loop), update
 * its live result and record a trend sample. Alerting is reconciled separately by EvaluateAlerts.
 */
class PollProbes
{
    public function __construct(private ProbeRunner $runner) {}

    /**
     * @param  list<int>  $deviceIds  the shard of devices whose probes to run (null = all)
     * @return int number of probes checked
     */
    public function __invoke(?array $deviceIds = null): int
    {
        $probes = Probe::where('enabled', true)
            ->when($deviceIds !== null, fn ($q) => $q->whereIn('device_id', $deviceIds))
            ->with('device:id,mgmt_ip')
            ->get()
            ->filter(fn (Probe $p) => $p->isDue());

        if ($probes->isEmpty()) {
            return 0;
        }

        $now = now();
        $samples = [];
        $checked = 0;

        foreach ($probes as $probe) {
            try {
                $result = $this->runner->run($probe);
            } catch (\Throwable $e) {
                EngineLog::warning('probe: run failed', ['probe_id' => $probe->id, 'error' => $e->getMessage()]);
                continue;
            }
            $checked++;

            $threshold = max(1, $probe->fail_threshold);
            $streak = $probe->fail_streak;
            if ($result->up) {
                $newStreak = 0;
                $newStatus = DeviceStatus::Up;
            } else {
                $newStreak = min($streak + 1, $threshold);
                // Hold the current status until we've missed `threshold` checks in a row.
                $newStatus = $newStreak >= $threshold ? DeviceStatus::Down : ($probe->status ?? DeviceStatus::Unknown);
            }

            $probe->forceFill([
                'status' => $newStatus,
                'latency_ms' => $result->latencyMs,
                'message' => $result->message,
                'cert_expires_at' => $result->certExpiresAt,
                'fail_streak' => $newStreak,
                'checked_at' => $now,
            ])->save();

            $samples[] = [
                'probe_id' => $probe->id,
                'ts' => $now,
                'up' => $result->up,
                'latency_ms' => $result->latencyMs,
            ];
        }

        if ($samples !== []) {
            DB::table('probe_samples')->insert($samples);
        }

        EngineLog::debug('probe: batch complete', ['checked' => $checked]);

        return $checked;
    }
}
