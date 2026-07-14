<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror/cache of RouterOS upgrade packages (.npk). We download the per-arch package for a
 * chosen version once, cache it locally, and let devices pull it from us (or straight from
 * MikroTik). Retained for `mymate.routeros.package_retention_days` (default 90), with a
 * manual delete. `token` gates the unauthenticated serving URL that routers fetch from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routeros_packages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('version', 32);
            $table->string('arch', 32);
            $table->string('channel', 32)->nullable();     // stable | long-term | testing (informational)
            $table->string('status', 16)->default('pending'); // pending | ready | failed
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('path')->nullable();            // storage path of the cached .npk
            $table->string('token', 64);                   // unguessable id for the serving URL
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();   // when the download completed
            $table->timestamps();

            $table->unique(['version', 'arch']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routeros_packages');
    }
};
