<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow a link end to carry no interface, so a ping-only device (poll_method=none, no
 * discovered interfaces) can still be linked into the topology from the other end. The
 * link's throughput/util then comes from whichever end does have an interface.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Either end may now be null (the ping-only end). Device ids stay required.
        DB::statement('ALTER TABLE links ALTER COLUMN a_interface_id DROP NOT NULL');
        DB::statement('ALTER TABLE links ALTER COLUMN b_interface_id DROP NOT NULL');

        // The old unique(a_interface_id, b_interface_id) can't express "only when both are
        // set" (Postgres treats NULLs as distinct, so it wouldn't catch duplicate ping-only
        // links anyway). Replace it with a partial unique index that keeps the no-duplicate
        // guarantee for real interface-to-interface links; null-ended links are deduped in
        // the Form Request instead.
        DB::statement('ALTER TABLE links DROP CONSTRAINT IF EXISTS links_a_interface_id_b_interface_id_unique');
        DB::statement('DROP INDEX IF EXISTS links_a_interface_id_b_interface_id_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX links_iface_pair_unique ON links (a_interface_id, b_interface_id)
            WHERE a_interface_id IS NOT NULL AND b_interface_id IS NOT NULL
        SQL);

        // Only enforce interface-belongs-to-device for ends that actually have an interface.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION links_interface_belongs_to_device()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.a_interface_id IS NOT NULL
                   AND (SELECT device_id FROM interfaces WHERE id = NEW.a_interface_id) IS DISTINCT FROM NEW.a_device_id THEN
                    RAISE EXCEPTION 'a_interface_id % does not belong to a_device_id %', NEW.a_interface_id, NEW.a_device_id;
                END IF;
                IF NEW.b_interface_id IS NOT NULL
                   AND (SELECT device_id FROM interfaces WHERE id = NEW.b_interface_id) IS DISTINCT FROM NEW.b_device_id THEN
                    RAISE EXCEPTION 'b_interface_id % does not belong to b_device_id %', NEW.b_interface_id, NEW.b_device_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Restore the strict belongs-check (no null guard).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION links_interface_belongs_to_device()
            RETURNS trigger AS $$
            BEGIN
                IF (SELECT device_id FROM interfaces WHERE id = NEW.a_interface_id) IS DISTINCT FROM NEW.a_device_id THEN
                    RAISE EXCEPTION 'a_interface_id % does not belong to a_device_id %', NEW.a_interface_id, NEW.a_device_id;
                END IF;
                IF (SELECT device_id FROM interfaces WHERE id = NEW.b_interface_id) IS DISTINCT FROM NEW.b_device_id THEN
                    RAISE EXCEPTION 'b_interface_id % does not belong to b_device_id %', NEW.b_interface_id, NEW.b_device_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP INDEX IF EXISTS links_iface_pair_unique');
        // These fail if any null-ended links exist; that's expected on a rollback.
        DB::statement('ALTER TABLE links ALTER COLUMN a_interface_id SET NOT NULL');
        DB::statement('ALTER TABLE links ALTER COLUMN b_interface_id SET NOT NULL');
        DB::statement('ALTER TABLE links ADD CONSTRAINT links_a_interface_id_b_interface_id_unique UNIQUE (a_interface_id, b_interface_id)');
    }
};
