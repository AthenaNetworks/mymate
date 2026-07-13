<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An SSH private key on a credential, for key-based SSH (e.g. a key My Mate had Rusted
 * install on a MikroTik over the API). Encrypted at rest like the other secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->text('private_key')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn('private_key');
        });
    }
};
