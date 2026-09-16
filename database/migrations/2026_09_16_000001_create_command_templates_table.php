<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('command');
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['enabled', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_templates');
    }
};
