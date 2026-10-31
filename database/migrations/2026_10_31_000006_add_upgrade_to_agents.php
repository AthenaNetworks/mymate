<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote agent upgrades: what the agent says it can do (from its hello - older agents send
 * nothing, so null) and how the last upgrade went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->json('capabilities')->nullable();
            $table->string('upgrade_status', 20)->nullable();
            $table->string('upgrade_version', 40)->nullable();
            $table->string('upgrade_message', 500)->nullable();
            $table->timestamp('upgrade_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['capabilities', 'upgrade_status', 'upgrade_version', 'upgrade_message', 'upgrade_at']);
        });
    }
};
