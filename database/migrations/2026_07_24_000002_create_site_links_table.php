<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backhaul links between two sites - the physical topology of what connects to what.
 *
 * Unlike the interface-level device links (which need both ends polled), these are site-to-site
 * and imported from the OSS that actually knows the backbone, so the geo map can draw the real
 * tower-to-tower backhauls instead of inferring them from device links between possibly-misplaced
 * devices. `external_ref` makes an import idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_a_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_b_id')->constrained('sites')->cascadeOnDelete();
            $table->string('media_type', 16)->nullable(); // fiber | wireless | other
            $table->string('external_ref')->nullable();
            $table->timestamps();

            $table->unique('external_ref');
            $table->index(['site_a_id', 'site_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_links');
    }
};
