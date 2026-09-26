<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom background image per logical map (GitHub #37): a floor plan, site photo or rack diagram
 * drawn behind the devices. The file lives on the local disk (background_path); the rest is its
 * natural size and where it sits on the canvas, in flow coordinates. background_version changes on
 * every upload so the image URL can be cached hard and still pick up a replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->string('background_path')->nullable();
            $table->string('background_mime', 32)->nullable();
            $table->string('background_version', 32)->nullable();
            $table->unsignedInteger('background_width')->nullable();
            $table->unsignedInteger('background_height')->nullable();
            $table->double('background_x')->default(0);
            $table->double('background_y')->default(0);
            $table->double('background_scale')->default(1);
            $table->double('background_opacity')->default(0.6);
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn([
                'background_path', 'background_mime', 'background_version',
                'background_width', 'background_height',
                'background_x', 'background_y', 'background_scale', 'background_opacity',
            ]);
        });
    }
};
