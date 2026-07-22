<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSPF cost (outbound metric) per interface (GitHub #11), read over the RouterOS API and shown
 * on the map link. Null = not an OSPF interface / not read. Directional: each end of a link
 * carries its own cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->unsignedInteger('ospf_cost')->nullable()->after('oper_status');
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table) {
            $table->dropColumn('ospf_cost');
        });
    }
};
