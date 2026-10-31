<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub #53: an image library an admin uploads to once and reuses - as a device's icon
 * (devices.icon = "img:<id>") and as pictures placed on a map (a logo, a legend, a photo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_assets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('path');
            $table->string('mime', 32);
            $table->string('version', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
        });

        Schema::create('map_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_asset_id')->constrained()->cascadeOnDelete();
            $table->double('x')->default(0);
            $table->double('y')->default(0);
            $table->double('width')->default(160); // flow px; height follows the aspect ratio
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_images');
        Schema::dropIfExists('image_assets');
    }
};
