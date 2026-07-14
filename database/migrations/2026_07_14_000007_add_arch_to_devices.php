<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RouterOS CPU architecture (arm / arm64 / mipsbe / mmips / tile / x86 ...), read from
 * /system/resource architecture-name. Needed to pick the right per-arch .npk when upgrading
 * a device to a chosen version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('arch', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('arch');
        });
    }
};
