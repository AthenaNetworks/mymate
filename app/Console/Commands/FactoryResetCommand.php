<?php

namespace App\Console\Commands;

use App\Actions\System\FactoryReset;
use Illuminate\Console\Command;

class FactoryResetCommand extends Command
{
    protected $signature = 'mymate:factory-reset {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe all monitoring data (devices, maps, credentials, history) and keep only admin accounts';

    public function handle(FactoryReset $reset): int
    {
        if (! $this->option('force') && ! $this->confirm('This permanently deletes ALL devices, maps, credentials and history, keeping only admin accounts. Continue?')) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $reset();
        $this->info('Factory reset complete - all monitoring data cleared, admin accounts retained.');

        return self::SUCCESS;
    }
}
