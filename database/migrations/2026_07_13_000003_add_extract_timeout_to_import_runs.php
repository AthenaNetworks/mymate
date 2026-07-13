<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-import extraction time limit (seconds). Big Dude databases (lots of chart
 * history) can take longer than the default to reverse-engineer, so the import screen
 * lets the operator raise the limit for a given run. Null = use the config default
 * (mymate.import.extract_timeout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('extract_timeout')->nullable()->after('include_history');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropColumn('extract_timeout');
        });
    }
};
