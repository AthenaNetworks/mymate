<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alert policy scoping/targeting. A nullable `scope` JSON bag lets a
 * policy apply to a subset of devices instead of the whole fleet - mirroring The Dude's
 * per-device / per-template triggers. Shape:
 *   {"type":"all"}                                (default / null = every device)
 *   {"type":"device_type","device_type":"router"}
 *   {"type":"map","map_id":5}
 *   {"type":"devices","device_ids":[1,2,3]}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_policies', function (Blueprint $table): void {
            $table->json('scope')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('alert_policies', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
