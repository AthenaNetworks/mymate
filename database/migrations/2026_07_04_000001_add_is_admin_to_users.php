<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator role tier.  deliberately
 * shipped a single operator tier ("add roles/policies if needed"); this is the minimal
 * two-tier concept that lets **admins manage** accounts while a normal operator can only
 * **view** the roster.
 *
 * Bootstrap: every pre-existing account is backfilled to admin - they were all
 * hand-created on the box by trusted hands, and leaving them non-admin would lock
 * everyone out of the new Users screen on day one. Demote view-only operators from the UI.
 * Forward-only on live (never re-run down()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        // Existing operators keep the ability to manage (they were all admin-created).
        DB::table('users')->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
