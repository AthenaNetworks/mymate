<?php

namespace App\Console\Commands;

use App\Actions\History\RollupHistory;
use App\Support\EngineLog;
use Illuminate\Console\Command;

/**
 * Roll raw history samples up into the 5m and 1h tiers (GitHub #28). Scheduled every five
 * minutes (routes/console.php); each run does a bounded amount of work and the next carries on,
 * so after an upgrade the existing raw history is backfilled over the first few runs without
 * anyone doing anything. `--backfill` runs until everything is caught up in one go.
 */
class RollupHistoryCommand extends Command
{
    protected $signature = 'mymate:history:rollup
        {--backfill : Keep going until every tier is caught up, ignoring the time budget}
        {--budget= : Seconds to spend this run (default mymate.history.rollup.budget)}
        {--rewind : Forget progress and recompute the tiers from the oldest raw data still kept}';

    protected $description = 'Roll raw history samples up into the long-term 5m / 1h tiers';

    public function handle(RollupHistory $rollup): int
    {
        if ($this->option('rewind')) {
            RollupHistory::rewind();
        }

        $budget = $this->option('backfill') ? 0.0 : ($this->option('budget') !== null ? (float) $this->option('budget') : null);
        $started = microtime(true);
        $r = $rollup($budget);
        $took = round(microtime(true) - $started, 1);

        EngineLog::debug('history: rollup run', $r + ['seconds' => $took]);
        $this->info("History rollup - {$r['slices']} slice(s) in {$took}s, ".($r['caught_up'] ? 'caught up.' : 'more to do next run.'));

        return self::SUCCESS;
    }
}
