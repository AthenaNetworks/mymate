<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub #37: a wallboard share can show the geo map too. `view` picks what the link shows -
 * `logical` (the diagram, the only thing a share could show before), `geo`, or `both` with a
 * switcher. Existing links default to logical so they carry on exactly as they were, and only a
 * geo-enabled share ever hands out device coordinates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_shares', function (Blueprint $table): void {
            $table->string('view', 12)->default('logical')->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('map_shares', function (Blueprint $table): void {
            $table->dropColumn('view');
        });
    }
};
