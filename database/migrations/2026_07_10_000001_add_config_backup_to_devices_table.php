<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device config-backup integration. Backups are captured over SSH by
 * the external **Rusted** engine (github.com/JoshFinlayAU/rusted) - My Mate is its control
 * plane / API client. These columns are opt-in-per-device config plus a cached mirror of
 * the last run's result so the inspector can show status without calling Rusted per render.
 * All additive + nullable; the driver/status come straight from Rusted's model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->boolean('backup_enabled')->default(false); // opt-in: is this device backed up?
            $table->string('backup_driver')->nullable();        // Rusted driver, e.g. mikrotik_routeros
            $table->string('backup_status')->nullable();        // pending|ok|unchanged|failed (null = never)
            $table->string('backup_message')->nullable();
            $table->timestamp('backup_at')->nullable();         // when the last run finished
            $table->string('backup_commit')->nullable();        // git commit hash of the last stored config
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['backup_enabled', 'backup_driver', 'backup_status', 'backup_message', 'backup_at', 'backup_commit']);
        });
    }
};
