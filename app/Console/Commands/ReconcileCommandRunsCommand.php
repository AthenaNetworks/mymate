<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use App\Models\CommandRunTarget;
use App\Services\Tools\ToolRun;
use Illuminate\Console\Command;

class ReconcileCommandRunsCommand extends Command
{
    protected $signature = 'mymate:command-runs:reconcile';

    protected $description = 'Finalize command targets abandoned by a timed-out worker';

    public function handle(): int
    {
        $seconds = max(1, (int) config('mymate.command.stale_after_seconds', 300));
        $cutoff = now()->subSeconds($seconds);
        $runIds = [];
        $count = 0;

        CommandRunTarget::query()
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->chunkById(500, function ($targets) use (&$runIds, &$count): void {
                foreach ($targets as $target) {
                    $updated = CommandRunTarget::query()
                        ->whereKey($target->id)
                        ->where('status', 'running')
                        ->update([
                            'status' => 'failed',
                            'error' => 'Worker timed out before the command completed.',
                            'finished_at' => now(),
                        ]);
                    if ($updated === 1) {
                        $runIds[$target->run_id] = true;
                        $count++;
                        $target->refresh();
                        $this->publishTarget($target);
                    }
                }
            });

        foreach (array_keys($runIds) as $runId) {
            $run = CommandRun::find($runId);
            if ($run === null) {
                continue;
            }
            $targets = $run->targets()->get();
            $statuses = $targets->pluck('status');
            $allTerminal = $statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => in_array($status, ['success', 'failed', 'stopped'], true));
            if ($allTerminal) {
                $run->update([
                    'status' => $statuses->contains('failed') ? 'failed' : ($statuses->contains('stopped') ? 'stopped' : 'completed'),
                    'completed_at' => now(),
                ]);
            }
            ToolRun::update($run->id, function (array $result) use ($targets, $allTerminal): array {
                foreach ($targets as $target) {
                    $key = $target->device_id === null ? collect($result['devices'] ?? [])->search(fn (array $row): bool => ($row['name'] ?? null) === $target->device_name) : (string) $target->device_id;
                    if ($key !== false && $key !== null) {
                        $result['devices'][$key] = array_merge((array) ($result['devices'][$key] ?? []), [
                            'name' => $target->device_name,
                            'status' => $target->status,
                            'output' => $target->output,
                            'error' => $target->error,
                        ]);
                    }
                }
                if ($allTerminal) {
                    $result['complete'] = true;
                }

                return $result;
            });
        }

        $this->info("Reconciled {$count} abandoned command target(s).");

        return self::SUCCESS;
    }

    private function publishTarget(CommandRunTarget $target): void
    {
        ToolRun::update($target->run_id, function (array $result) use ($target): array {
            $key = $target->device_id === null ? collect($result['devices'] ?? [])->search(fn (array $row): bool => ($row['name'] ?? null) === $target->device_name) : (string) $target->device_id;
            if ($key !== false && $key !== null) {
                $result['devices'][$key] = array_merge((array) ($result['devices'][$key] ?? []), [
                    'name' => $target->device_name,
                    'status' => $target->status,
                    'output' => $target->output,
                    'error' => $target->error,
                ]);
            }

            return $result;
        });
    }
}
