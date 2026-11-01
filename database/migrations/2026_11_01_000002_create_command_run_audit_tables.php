<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('command_templates')->nullOnDelete();
            $table->text('command');
            $table->char('command_hash', 64);
            $table->unsignedSmallInteger('timeout_seconds');
            $table->unsignedSmallInteger('target_count');
            $table->string('status', 20)->default('running');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('command_run_targets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('device_name', 255);
            $table->string('status', 20)->default('queued');
            $table->text('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->foreign('run_id')->references('id')->on('command_runs')->cascadeOnDelete();
            $table->unique(['run_id', 'device_id']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_run_targets');
        Schema::dropIfExists('command_runs');
    }
};
