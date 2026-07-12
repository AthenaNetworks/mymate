<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Symmetric recovery gate. Mirrors `breach_started_at`/`pending` on the
     * recovery side: a `firing` event whose condition clears starts `recovery_started_at`
     * and moves to `resolving` rather than resolving immediately, so a flapping recovery
     * doesn't spam a notification per blip.
     */
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->timestamp('recovery_started_at')->nullable()->after('fired_at');
        });
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->dropColumn('recovery_started_at');
        });
    }
};
