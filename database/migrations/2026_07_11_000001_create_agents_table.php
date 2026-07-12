<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote agents (probes). A lightweight agent runs inside a management network, dials an
 * outbound encrypted WebSocket to this app, and polls/discovers devices locally on our
 * behalf - so an out-of-band (SaaS) install never needs the management network exposed.
 *
 * The agent authenticates with a bearer token; we store only its SHA-256 hash (the plaintext
 * is shown once at enrolment). `status`/`last_seen_at`/`version`/`platform` are updated by the
 * agent hub as connections come and go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();     // sha256 hex of the bearer token
            $table->string('status')->default('enrolled');  // enrolled | online | offline
            $table->timestamp('last_seen_at')->nullable();
            $table->string('version')->nullable();           // agent build version (reported on connect)
            $table->string('platform')->nullable();          // e.g. linux/arm64 (reported on connect)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
