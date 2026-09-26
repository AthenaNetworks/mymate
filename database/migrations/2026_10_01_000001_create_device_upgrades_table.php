<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firmware upgrade history. Until now the device only kept the latest outcome
 * (devices.upgrade_status / upgrade_message / upgrade_at) so the Events tab could show one
 * upgrade at most. This keeps one row per attempt, written by RecordUpgradeStatus as the
 * attempt moves through queued -> checking -> downloading -> rebooting -> done/failed.
 *
 * batch_id groups the devices of one bulk request (there's no batches table, it's just a uuid
 * minted when the request comes in). user_id is a soft ref on purpose, deleting a user
 * shouldn't take the history with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable(); // who asked for it, no FK
            $table->string('from_version')->nullable();
            $table->string('to_version')->nullable();
            $table->string('status'); // UpgradeStatus value, the latest one for this attempt
            $table->string('message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();  // a worker picked it up (checking)
            $table->timestamp('finished_at')->nullable(); // null = still running
            $table->timestamps();

            $table->index(['device_id', 'id']);
        });

        // Backfill: whatever's on the device today becomes its first history row, so the
        // Events tab isn't empty after the upgrade. A finished "done" means os_version is
        // already the new version, so that's the to_version and we don't know the from.
        DB::statement(<<<'SQL'
            INSERT INTO device_upgrades (device_id, from_version, to_version, status, message, queued_at, started_at, finished_at, created_at, updated_at)
            SELECT id,
                   CASE WHEN upgrade_status = 'done' THEN NULL ELSE os_version END,
                   CASE WHEN upgrade_status = 'done' THEN os_version ELSE latest_version END,
                   COALESCE(upgrade_status, 'failed'),
                   upgrade_message,
                   upgrade_at,
                   upgrade_at,
                   CASE WHEN upgrade_status IN ('queued', 'checking', 'downloading', 'rebooting') THEN NULL ELSE upgrade_at END,
                   COALESCE(upgrade_at, NOW()),
                   COALESCE(upgrade_at, NOW())
            FROM devices
            WHERE upgrade_status IS NOT NULL OR upgrade_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('device_upgrades');
    }
};
