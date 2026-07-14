<?php

namespace App\Console\Commands;

use App\Actions\Upgrade\FetchRouterosPackage;
use App\Models\RouterosPackage;
use Illuminate\Console\Command;

/**
 * Sweep cached RouterOS packages older than the retention window (default 90 days). Runs
 * daily from the scheduler; packages can also be deleted by hand in the UI.
 */
class PruneRouterosPackagesCommand extends Command
{
    protected $signature = 'mymate:routeros:prune-packages';

    protected $description = 'Delete cached RouterOS packages older than the retention window';

    public function handle(FetchRouterosPackage $fetch): int
    {
        $days = max(1, (int) config('mymate.upgrade.package_retention_days', 90));
        $cutoff = now()->subDays($days);

        $stale = RouterosPackage::where('fetched_at', '<', $cutoff)
            ->orWhere(fn ($q) => $q->whereNull('fetched_at')->where('created_at', '<', $cutoff))
            ->get();

        foreach ($stale as $pkg) {
            $fetch->delete($pkg);
        }

        $this->info("Pruned {$stale->count()} cached RouterOS package(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
