<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Operator-chosen glyph + colour override for the map/sidebar. Null = auto (product
            // photo / vendor mark / device-type family icon).
            $table->string('icon', 40)->nullable()->after('device_type');
            $table->string('icon_color', 20)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_color']);
        });
    }
};
