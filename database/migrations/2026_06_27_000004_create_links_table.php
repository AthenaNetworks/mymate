<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('a_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('a_interface_id')->constrained('interfaces')->cascadeOnDelete();
            $table->foreignId('b_device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('b_interface_id')->constrained('interfaces')->cascadeOnDelete();
            $table->timestamps();

            // No duplicate link between the same pair of interface ends.
            $table->unique(['a_interface_id', 'b_interface_id']);
        });

        // Integrity guard: each interface must belong to the device on its end.
        // A plain CHECK can't subquery, so enforce at DB level with a trigger
        // (the P6 Form Request adds a friendly message on top).
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

            CREATE TRIGGER trg_links_interface_belongs
            BEFORE INSERT OR UPDATE ON links
            FOR EACH ROW EXECUTE FUNCTION links_interface_belongs_to_device();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_links_interface_belongs ON links;');
        DB::unprepared('DROP FUNCTION IF EXISTS links_interface_belongs_to_device();');
        Schema::dropIfExists('links');
    }
};
