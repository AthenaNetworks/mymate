<?php

namespace App\Console\Commands;

use App\Actions\Cmr\SyncCmrDevices;
use App\Support\CmrSettings;
use Illuminate\Console\Command;

/** Pull the CMR controller's paired devices into the review queue / device mirror. Scheduled every minute. */
class CmrSyncCommand extends Command
{
    protected $signature = 'mymate:cmr:sync';

    protected $description = 'Sync devices paired with the CMR controller';

    public function handle(CmrSettings $settings, SyncCmrDevices $sync): int
    {
        if (! $settings->active()) {
            $this->line('CMR controller is off or not configured - nothing to do.');

            return self::SUCCESS;
        }

        try {
            $r = $sync();
        } catch (\Throwable $e) {
            $this->error('CMR sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("CMR sync: {$r['devices']} device(s) refreshed, {$r['candidates']} candidate(s) in the queue.");

        return self::SUCCESS;
    }
}
