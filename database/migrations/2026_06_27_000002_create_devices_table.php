<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mgmt_ip', 45); // room for IPv6
            $table->string('poll_method'); // snmp | routeros (enum cast on model)
            $table->foreignId('credential_id')->nullable()
                ->constrained('credentials')->nullOnDelete();
            $table->string('status')->default('unknown'); // up | down | unknown (enum cast)
            $table->timestamp('last_change')->nullable();
            $table->double('map_x')->default(0);
            $table->double('map_y')->default(0);
            $table->timestamps();

            $table->index('status'); // hot column
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
