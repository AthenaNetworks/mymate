<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // snmp | routeros (enum cast on model)
            // Encrypted secrets - text (not string): the encrypted payload overflows varchar(255).
            $table->text('snmp_community')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->unsignedInteger('api_port')->default(8728);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
