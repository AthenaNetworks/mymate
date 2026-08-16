<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-note styling for map notes: a background colour and a size preset, alongside the existing
 * text `color`. Lets a note read on both the light and dark themes (the old default was a faint
 * white overlay that vanished on light) and lets an operator make a label stand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_notes', function (Blueprint $table) {
            $table->string('background', 20)->nullable()->after('color'); // #rrggbb, null = theme default
            $table->string('size', 8)->nullable()->after('background');    // sm | md | lg, null = md
        });
    }

    public function down(): void
    {
        Schema::table('map_notes', function (Blueprint $table) {
            $table->dropColumn(['background', 'size']);
        });
    }
};
