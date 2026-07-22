<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a custom sensor walk an SNMP table and reduce it to one value (GitHub #10), not just GET
 * a scalar. `mode` = get (default, unchanged) | walk; `agg` = how a walk is reduced
 * (sum/avg/max/min/count). Existing sensors default to the old scalar-GET behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensors', function (Blueprint $table) {
            $table->string('mode', 8)->default('get')->after('oid');
            $table->string('agg', 8)->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('sensors', function (Blueprint $table) {
            $table->dropColumn(['mode', 'agg']);
        });
    }
};
