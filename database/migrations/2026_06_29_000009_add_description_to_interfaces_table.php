<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface description: the human label an operator sets on a
 * port - captured best-effort at discovery (SNMP ifAlias, RouterOS interface
 * comment) and shown as secondary text in the link-binder picker. Additive +
 * nullable; the live up/down + throughput paths are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->string('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
