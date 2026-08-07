<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Granular access (GitHub #28): a "restricted" operator is a read-only user confined to the maps
 * they've been granted (and those maps' sub-maps). `map_user` records the grants. Unrestricted
 * users and admins are unaffected - they still see everything - so existing installs are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // A privilege flag, like is_admin - set explicitly by an admin, never mass-assigned.
            $table->boolean('restricted')->default(false)->after('is_admin');
        });

        Schema::create('map_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->primary(['user_id', 'map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_user');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('restricted');
        });
    }
};
