<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * : alerting. Policies fire on a condition; transports deliver them
 * (Email/Slack/Teams); a policy<->transport pivot wires them up; alert_events is the
 * fired/resolved log (also the dedupe source - one open event per dedupe_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('condition'); // App\Enums\AlertCondition
            $table->json('params')->nullable(); // e.g. {"threshold": 90}
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_transports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type'); // App\Enums\TransportType
            $table->text('config'); // encrypted JSON {email|webhook_url} - never returned
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_policy_transport', function (Blueprint $table): void {
            $table->foreignId('alert_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_transport_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_policy_id', 'alert_transport_id']);
        });

        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_policy_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key');
            $table->string('status')->default('firing'); // firing | resolved
            $table->text('message');
            $table->boolean('delivered')->default(false);
            $table->timestamp('fired_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['alert_policy_id', 'status', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_policy_transport');
        Schema::dropIfExists('alert_transports');
        Schema::dropIfExists('alert_policies');
    }
};
