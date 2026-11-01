<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('command');
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->boolean('enabled')->default(true);
            // Nulled, not restricted: a template must never stop an operator account being deleted.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['enabled', 'name']);
        });

        // Unique among live templates only, so a deleted template's name can be reused.
        DB::statement('CREATE UNIQUE INDEX command_templates_name_unique ON command_templates (name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('command_templates');
    }
};
