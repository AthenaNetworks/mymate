<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            // '1' | '2c' | '3'. Existing SNMP creds are community-based v2c.
            $table->string('snmp_version', 4)->default('2c')->after('snmp_community');
            // v3 USM. The passphrases are secrets (encrypted, text - the payload overflows varchar).
            $table->string('snmp_sec_name')->nullable()->after('snmp_version');       // USM user
            $table->string('snmp_sec_level', 16)->nullable()->after('snmp_sec_name'); // noAuthNoPriv|authNoPriv|authPriv
            $table->string('snmp_auth_protocol', 16)->nullable()->after('snmp_sec_level'); // MD5|SHA|SHA-256...
            $table->text('snmp_auth_passphrase')->nullable()->after('snmp_auth_protocol');
            $table->string('snmp_priv_protocol', 16)->nullable()->after('snmp_auth_passphrase'); // DES|AES|AES-256...
            $table->text('snmp_priv_passphrase')->nullable()->after('snmp_priv_protocol');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table) {
            $table->dropColumn([
                'snmp_version', 'snmp_sec_name', 'snmp_sec_level',
                'snmp_auth_protocol', 'snmp_auth_passphrase',
                'snmp_priv_protocol', 'snmp_priv_passphrase',
            ]);
        });
    }
};
