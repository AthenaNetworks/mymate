<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert acknowledgement: record who marked an alert as being handled, and when. Purely a
 * human workflow marker - it doesn't change delivery, but it lets a NOC see an alert is
 * owned and stops it counting as unacknowledged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->timestamp('acknowledged_at')->nullable()->after('fired_at');
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table): void {
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });
    }
};
