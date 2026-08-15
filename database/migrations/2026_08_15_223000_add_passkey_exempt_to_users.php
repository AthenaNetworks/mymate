<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operator opt-out from a mandatory-passkey requirement. Meant for accounts that can't do a
 * WebAuthn ceremony - a wallboard/kiosk logged in on a TV, say - so turning on "require passkeys"
 * doesn't lock them out. Like is_admin/restricted it's a privilege, set explicitly by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('passkey_exempt')->default(false)->after('restricted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('passkey_exempt');
        });
    }
};
