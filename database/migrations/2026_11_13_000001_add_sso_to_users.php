<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single sign-on (OpenID Connect, Entra ID first). An operator can now be linked to an identity
 * at the provider - matched by issuer + subject (Entra's object id), never by email, which can
 * change - and may have no password at all (an account created by SSO). Such an account can't use
 * the password login.
 *
 * `disabled_at` lets an account be switched off without deleting it: its sessions stop working
 * and its API keys are refused. `disabled_reason` says who did it - an admin ('admin'), or SSO
 * when the provider stopped granting a My Mate role ('sso'). Only an SSO disable is lifted by a
 * later SSO sign-in that has the role again; an admin's disable stays until an admin lifts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('sso_issuer')->nullable()->after('password');
            $table->string('sso_subject')->nullable()->after('sso_issuer');
            $table->timestamp('sso_last_login_at')->nullable()->after('sso_subject');
            $table->timestamp('disabled_at')->nullable()->after('passkey_exempt');
            $table->string('disabled_reason', 16)->nullable()->after('disabled_at');
            $table->unique(['sso_issuer', 'sso_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_issuer', 'sso_subject']);
            $table->dropColumn(['sso_issuer', 'sso_subject', 'sso_last_login_at', 'disabled_at', 'disabled_reason']);
        });
        // Not restoring NOT NULL on password: SSO-only accounts would have no value to keep.
    }
};
