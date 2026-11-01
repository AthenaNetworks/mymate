<?php

namespace App\Console\Commands;

use App\Models\CommandRunTarget;
use Illuminate\Console\Command;

class PruneCommandOutputCommand extends Command
{
    protected $signature = 'mymate:command-runs:prune-output';

    protected $description = 'Remove expired SSH command output and errors from audit rows';

    public function handle(): int
    {
        $days = max(1, (int) config('mymate.command.output_retention_days', 30));
        $cutoff = now()->subDays($days);
        $count = 0;

        CommandRunTarget::query()
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('output')->orWhereNotNull('error');
            })
            ->chunkById(500, function ($targets) use (&$count): void {
                $count += CommandRunTarget::query()
                    ->whereKey($targets->pluck('id'))
                    ->update(['output' => null, 'error' => null]);
            });

        $this->info("Pruned output from {$count} command target(s).");

        return self::SUCCESS;
    }
}
