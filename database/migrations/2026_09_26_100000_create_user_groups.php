<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named operator groups (GitHub #28). A group carries the same access an admin can already set on
 * a single operator - read-only on everything, or restricted to a set of maps - so it can be set
 * once for "NOC" or "Field techs - North" and applied to everyone in it.
 *
 * Only new tables. Nobody is in a group after this runs, and an operator with no groups resolves
 * exactly as before (their own restricted flag + map grants), so existing access is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // Same meaning as users.restricted: members only see this group's maps (and sub-maps).
            // false = read-only across the whole fleet.
            $table->boolean('restricted')->default(true);
            $table->timestamps();
        });

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('map_user_group', function (Blueprint $table): void {
            $table->foreignId('user_group_id')->constrained('user_groups')->cascadeOnDelete();
            $table->foreignId('map_id')->constrained('maps')->cascadeOnDelete();
            $table->primary(['user_group_id', 'map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_user_group');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};
