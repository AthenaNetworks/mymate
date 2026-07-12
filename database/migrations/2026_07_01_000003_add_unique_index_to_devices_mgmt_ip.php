<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unique DB constraint on devices.mgmt_ip ( - closes a discovery-candidate
 * promotion race found via adversarial review: two near-simultaneous "Approve" clicks
 * for the same candidate could both pass PromoteCandidate's app-level "does a device
 * exist at this IP?" check before either committed, creating two devices for one IP).
 * The Dude importer already treats mgmt_ip as a natural key for its upsert matching,
 * so this formalises an assumption the app already made - verified no existing
 * duplicates before adding it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->unique('mgmt_ip');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['mgmt_ip']);
        });
    }
};
