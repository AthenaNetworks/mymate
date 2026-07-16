<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            // The node side each end attaches to (React Flow handle id: s-top/s-bottom/... etc),
            // captured from where the operator dragged. Null = auto (floating edge picks the
            // facing side), so existing links keep their current behaviour.
            $table->string('a_handle', 16)->nullable()->after('a_interface_id');
            $table->string('b_handle', 16)->nullable()->after('b_interface_id');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn(['a_handle', 'b_handle']);
        });
    }
};
