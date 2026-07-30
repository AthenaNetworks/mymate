<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which site a device sits at, and how that was decided.
 *
 * `site_source` mirrors the existing `geo_source` idea: an automatic pass (nearest-site, or an
 * import keyed on an external system) must never overwrite a placement an operator made by
 * hand, so anything set to `manual` is skipped by the assign action.
 *
 * Deleting a site nulls the link rather than deleting devices - losing a tower record should
 * never take the gear monitoring with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('geo_source')
                ->constrained('sites')->nullOnDelete();
            $table->string('site_source', 12)->nullable()->after('site_id'); // manual | import | nearest
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn('site_source');
        });
    }
};
