<?php

namespace App\Jobs\Tools;

use App\Actions\Backup\RegisterBackupDevice;
use App\Models\Device;
use App\Models\CommandRun;
use App\Models\CommandRunTarget;
use App\Services\Backup\RustedClient;
use App\Services\Tools\ToolRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Execute one bounded SSH command for one selected MyMate device. */
class RunCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    private ?float $startedAt = null;

    public function __construct(
        public string $runId,
        public int $deviceId,
        public string $command,
        public int $timeoutSeconds,
    ) {
        $this->onQueue('command');
    }

    public function handle(RustedClient $client, RegisterBackupDevice $register): void
    {
        $device = Device::find($this->deviceId);
        if ($device === null) {
            $this->finish('failed', 'Device no longer exists.', '');
            return;
        }

        $this->startedAt = microtime(true);
        $this->setStatus('running');

        if (ToolRun::stopRequested($this->runId)) {
            $this->finish('stopped', 'Run cancelled before execution.', '');
            return;
        }

        try {
            ($register)($device);
            $result = $client->execute(RegisterBackupDevice::rustedName($device), $this->command, $this->timeoutSeconds);
            $status = ($result['status'] ?? '') === 'success' ? 'success' : 'failed';
            $output = is_string($result['output'] ?? null) ? $result['output'] : '';
            $this->finish($status, $status === 'success' ? '' : $this->bounded($output), $this->bounded($output));
        } catch (Throwable $e) {
            $this->finish('failed', $this->boundedError($e->getMessage()), '');
        }
    }

    private function setStatus(string $status): void
    {
        ToolRun::update($this->runId, function (array $result) use ($status): array {
            if (isset($result['devices'][(string) $this->deviceId])) {
                $result['devices'][(string) $this->deviceId]['status'] = $status;
            }
            return $result;
        });
        CommandRunTarget::where('run_id', $this->runId)
            ->where('device_id', $this->deviceId)
            ->update([
                'status' => $status,
                'started_at' => $status === 'running' ? now() : null,
            ]);
    }

    private function finish(string $status, string $error, string $output): void
    {
        ToolRun::update($this->runId, function (array $result) use ($status, $error, $output): array {
            if (isset($result['devices'][(string) $this->deviceId])) {
                $row = &$result['devices'][(string) $this->deviceId];
                $row['status'] = $status;
                $row['output'] = $output;
                $row['error'] = $error !== '' ? $error : null;
            }
            $statuses = array_column($result['devices'] ?? [], 'status');
            if ($statuses !== [] && count(array_diff($statuses, ['success', 'failed', 'stopped'])) === 0) {
                $result['complete'] = true;
            }
            return $result;
        });

        $target = CommandRunTarget::where('run_id', $this->runId)
            ->where('device_id', $this->deviceId)
            ->first();
        if ($target !== null) {
            $target->update([
                'status' => $status,
                'output' => $output !== '' ? $output : null,
                'error' => $error !== '' ? $error : null,
                'duration_ms' => $this->startedAt === null ? null : max(0, (int) round((microtime(true) - $this->startedAt) * 1000)),
                'finished_at' => now(),
            ]);
        }

        $run = CommandRun::find($this->runId);
        if ($run !== null) {
            $statuses = $run->targets()->pluck('status')->all();
            if ($statuses !== [] && count(array_diff($statuses, ['success', 'failed', 'stopped'])) === 0) {
                $run->update([
                    'status' => in_array('failed', $statuses, true) ? 'failed' : (in_array('stopped', $statuses, true) ? 'stopped' : 'completed'),
                    'completed_at' => now(),
                ]);
            }
        }
    }

    private function bounded(string $output): string
    {
        return mb_strlen($output) > 16384
            ? mb_substr($output, 0, 16384)."\n[output truncated]"
            : $output;
    }

    private function boundedError(string $error): string
    {
        $error = trim($error);
        return mb_substr($error !== '' ? $error : 'Command failed.', 0, 300);
    }
}
