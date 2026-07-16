<?php

use App\Enums\AgentStatus;
use App\Jobs\ManageHistoryPartitionsJob;
use App\Models\Agent;
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

// Sweep cached RouterOS upgrade packages past the retention window (default 90 days).
Schedule::command('mymate:routeros:prune-packages')->daily()->name('routeros-package-prune')->withoutOverlapping();

// Reap silent agents. A connected agent heartbeats via the hub keepalive every ~30s; if an
// "online" one hasn't been heard from in 90s its socket is dead (e.g. a blackholed link that
// never sent a TCP close, which the raised /agent proxy timeout would otherwise mask for up to
// an hour) - flip it offline so the UI and job dispatch don't treat a gone agent as connected.
Schedule::call(function () {
    Agent::query()
        ->where('status', AgentStatus::Online)
        ->where('last_seen_at', '<', now()->subSeconds(90))
        ->update(['status' => AgentStatus::Offline]);
})->everyMinute()->name('agent-reap-stale')->withoutOverlapping();
