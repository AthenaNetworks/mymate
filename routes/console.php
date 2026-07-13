<?php

use App\Jobs\ManageHistoryPartitionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recent history: keep the interface_samples daily partitions rolling +
// drop expired ones. The mymate:loop daemon also does this on its own cadence; this
// is the standard scheduler path for deployments running `schedule:work`.
Schedule::job(new ManageHistoryPartitionsJob)->daily()->name('history-partitions')->withoutOverlapping();

// Device config backups: nightly, fan out one backup job per
// backup-enabled device onto the isolated `backup` queue (the SSH capture runs in the
// Rusted sidecar). Off-peak so a slow fleet-wide sweep doesn't compete with the day.
// Runs hourly; the command fires only when the operator-configured cadence
// (App\Support\BackupSchedule, editable on the Backups page) is due - so changing the
// schedule takes effect without restarting the scheduler.
Schedule::command('mymate:backup:run --scheduled')->hourly()->name('device-backups')->withoutOverlapping();
