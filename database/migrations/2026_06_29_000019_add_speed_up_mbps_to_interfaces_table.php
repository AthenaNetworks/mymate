<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            // Upstream/egress capacity override for asymmetric links (e.g. 500dn/50up).
            // Nullable -> falls back to speed_mbps (symmetric). Operator-set only;
            // discovery never writes it. The util_out denominator.
            $table->unsignedInteger('speed_up_mbps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interfaces', function (Blueprint $table): void {
            $table->dropColumn('speed_up_mbps');
        });
    }
};
