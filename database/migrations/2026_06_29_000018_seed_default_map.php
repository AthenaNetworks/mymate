<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * : seed a default "Main" map and place every existing device on it,
 * carrying its current `devices.map_x/map_y`. Additive - the old columns stay until
 * a later cleanup, so nothing on the live map moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('maps')->where('is_default', true)->exists()) {
            return;
        }

        $now = now();
        $mapId = DB::table('maps')->insertGetId([
            'name' => 'Main', 'is_default' => true, 'position' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (DB::table('devices')->get(['id', 'map_x', 'map_y']) as $device) {
            DB::table('device_map_positions')->insert([
                'device_id' => $device->id, 'map_id' => $mapId,
                'x' => $device->map_x, 'y' => $device->map_y,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Tables (and their rows) are dropped by 000017's down().
    }
};
