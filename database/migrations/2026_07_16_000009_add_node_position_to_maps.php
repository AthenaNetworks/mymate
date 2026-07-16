<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A child map's position when it's placed as a NODE on its parent map (for top-level overview
 * maps that show sub-maps and links rather than devices). Null = not placed on the parent
 * canvas; the map still exists and is navigable from the map list as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->double('node_x')->nullable()->after('position');
            $table->double('node_y')->nullable()->after('node_x');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table) {
            $table->dropColumn(['node_x', 'node_y']);
        });
    }
};
