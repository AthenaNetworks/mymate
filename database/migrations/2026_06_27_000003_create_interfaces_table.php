<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->unsignedInteger('if_index');
            $table->string('name');
            $table->unsignedInteger('speed_mbps')->nullable(); // user-overridable (wireless/tunnel)
            // SNMP delta state (RouterOS monitor-traffic path leaves these null):
            $table->bigInteger('last_in')->nullable();
            $table->bigInteger('last_out')->nullable();
            $table->timestamp('last_ts')->nullable();
            // Latest computed utilisation (broadcast each tick):
            $table->double('util_in')->nullable();
            $table->double('util_out')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'if_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interfaces');
    }
};
