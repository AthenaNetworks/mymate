<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sustained utilisation alerts. A breach is tracked from when it
     * first crosses the threshold (`breach_started_at`) and only flips from `pending` to
     * `firing` once it has been sustained for the policy's `params.duration_minutes`. So a
     * pending event hasn't fired yet -> `fired_at` becomes nullable.
     */
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->timestamp('breach_started_at')->nullable()->after('message');
            $table->timestamp('fired_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->dropColumn('breach_started_at');
            $table->timestamp('fired_at')->nullable(false)->change();
        });
    }
};
