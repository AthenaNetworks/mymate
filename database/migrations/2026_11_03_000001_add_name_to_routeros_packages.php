<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cache extra RouterOS packages (wifi-qcom-ac, container, ...) next to the main `routeros` one.
 * A RouterOS 7 router with an extra package installed won't upgrade unless that package is staged
 * at the new version too, so the mirror has to be able to serve it. Existing rows are all the
 * main package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routeros_packages', function (Blueprint $table): void {
            $table->string('name', 64)->default('routeros')->after('id');
            $table->dropUnique(['version', 'arch']);
            $table->unique(['name', 'version', 'arch']);
        });
    }

    public function down(): void
    {
        DB::table('routeros_packages')->where('name', '!=', 'routeros')->delete();

        Schema::table('routeros_packages', function (Blueprint $table): void {
            $table->dropUnique(['name', 'version', 'arch']);
            $table->unique(['version', 'arch']);
            $table->dropColumn('name');
        });
    }
};
