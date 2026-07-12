<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * : surface *why* interface discovery yielded nothing instead of
 * silently leaving 0 interfaces. `discovery_error` holds the last failure/empty
 * message (e.g. "SNMP timeout"); `discovered_at` stamps the last attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->text('discovery_error')->nullable();
            $table->timestamp('discovered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['discovery_error', 'discovered_at']);
        });
    }
};
