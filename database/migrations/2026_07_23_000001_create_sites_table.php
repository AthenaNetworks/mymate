<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sites: the physical locations gear lives at - a tower, a fiber cabinet, a POP, an exchange.
 *
 * Geo mode (GitHub #11) pins each device individually, which is fine for a handful of routers
 * but does not survive a real access network: an operator with a few thousand devices spread
 * over a few hundred towers does not want to drag a thousand pins, and the coordinates they
 * already trust live in their OSS/inventory, not here. A site carries the coordinates once and
 * every device at it inherits them, so placing a whole tower's worth of gear is one row.
 *
 * `external_ref` is the id this site has in whatever system owns the truth (an OSS/inventory, a
 * spreadsheet). It's the idempotency key for re-imports - re-running an import updates in
 * place instead of duplicating, so the source of truth can be re-synced on a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // tower | cabinet | pop | other - a label for filtering and map styling, not a
            // constraint. Kept as a string (not an enum column) so adding a kind is a code
            // change, never a migration on a live table.
            $table->string('kind', 16)->default('other');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->string('external_ref')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Import upserts on this, and it's the natural lookup for a re-sync.
            $table->unique('external_ref');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
