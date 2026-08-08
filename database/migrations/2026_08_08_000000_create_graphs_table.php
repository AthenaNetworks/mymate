<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom graphs (GitHub #28): a saved, named chart plotting any number of interfaces together,
 * with an optional combined total. `config` holds the definition - the metric (throughput or
 * utilisation), the series (interface + direction), and whether to draw the summed total - so the
 * shape can grow without a migration each time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graphs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->jsonb('config');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graphs');
    }
};
