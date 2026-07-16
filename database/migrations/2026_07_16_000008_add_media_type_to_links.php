<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link media type (fiber / ethernet / wireless / other) so the map can style a link by its
 * physical medium. Null = unspecified (styled as a plain link, as today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->string('media_type', 20)->nullable()->after('b_handle');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });
    }
};
