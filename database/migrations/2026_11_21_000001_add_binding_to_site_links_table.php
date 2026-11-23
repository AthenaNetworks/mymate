<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a site-to-site backhaul to the interfaces that actually carry it, and record the role the
 * circuit is meant to play in the backbone (GitHub #85).
 *
 * Without this, `site_links` is a drawing: it cannot show that a circuit is down, how loaded it
 * is, or that traffic has failed over to the backup. The columns are additive and nullable, so
 * every existing row (imported from the OSS) keeps working unbound.
 *
 * Integrity follows the device-link precedent (2026_07_12_000001_allow_null_link_interfaces):
 * either end may be unbound, but a bound interface must belong to a device at that end's site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_links', function (Blueprint $table) {
            // The interface at each end. Nullable: a far end may be unmonitored, ping-only, or
            // simply not discovered yet - the link is still real topology.
            $table->foreignId('a_interface_id')->nullable()->constrained('interfaces')->nullOnDelete();
            $table->foreignId('b_interface_id')->nullable()->constrained('interfaces')->nullOnDelete();

            // Where the circuit is *supposed* to be (documentation of intent), not a live fact.
            $table->string('role', 16)->default('unknown'); // primary | backup | unknown

            // Per-direction capacity overrides, mirroring DeviceLink. Null falls back to the
            // slower end's interface speed, then the default circuit speed.
            $table->unsignedInteger('capacity_ab_mbps')->nullable();
            $table->unsignedInteger('capacity_ba_mbps')->nullable();

            // Where the binding came from and how sure the discovery pass was (null = imported or
            // human-set then verified). Phase 2 writes `lldp`/`fdb`/... candidates, not bindings.
            $table->string('binding_source', 16)->default('import');
            $table->unsignedSmallInteger('binding_confidence')->nullable();
            $table->timestamp('verified_at')->nullable();
        });

        // One physical port can be the end of one backhaul, not several. Partial unique indexes
        // express that only for bound ends (Postgres treats NULLs as distinct anyway).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX site_links_a_interface_unique ON site_links (a_interface_id)
            WHERE a_interface_id IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX site_links_b_interface_unique ON site_links (b_interface_id)
            WHERE b_interface_id IS NOT NULL
        SQL);

        // The interface must be on gear at the site on its end. The same guard device links use,
        // but resolved through devices.site_id because a site link's ends are sites, not devices.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION site_links_interface_at_site()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.a_interface_id IS NOT NULL
                   AND (SELECT d.site_id FROM interfaces i JOIN devices d ON d.id = i.device_id
                        WHERE i.id = NEW.a_interface_id) IS DISTINCT FROM NEW.site_a_id THEN
                    RAISE EXCEPTION 'a_interface_id % is not at site %', NEW.a_interface_id, NEW.site_a_id;
                END IF;
                IF NEW.b_interface_id IS NOT NULL
                   AND (SELECT d.site_id FROM interfaces i JOIN devices d ON d.id = i.device_id
                        WHERE i.id = NEW.b_interface_id) IS DISTINCT FROM NEW.site_b_id THEN
                    RAISE EXCEPTION 'b_interface_id % is not at site %', NEW.b_interface_id, NEW.site_b_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_site_links_interface_at_site
            BEFORE INSERT OR UPDATE ON site_links
            FOR EACH ROW EXECUTE FUNCTION site_links_interface_at_site();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_site_links_interface_at_site ON site_links;');
        DB::unprepared('DROP FUNCTION IF EXISTS site_links_interface_at_site();');
        DB::statement('DROP INDEX IF EXISTS site_links_a_interface_unique');
        DB::statement('DROP INDEX IF EXISTS site_links_b_interface_unique');

        Schema::table('site_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('a_interface_id');
            $table->dropConstrainedForeignId('b_interface_id');
            $table->dropColumn(['role', 'capacity_ab_mbps', 'capacity_ba_mbps', 'binding_source', 'binding_confidence', 'verified_at']);
        });
    }
};
