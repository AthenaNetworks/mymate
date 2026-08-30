<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show on the device face" for a custom sensor (GitHub #40): when set, the sensor's current
 * reading is drawn as a label on the device's map card (Dude-style, e.g. "22C Temp"), alongside
 * its in-scope devices' other face sensors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensors', function (Blueprint $table): void {
            $table->boolean('on_face')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('sensors', function (Blueprint $table): void {
            $table->dropColumn('on_face');
        });
    }
};
