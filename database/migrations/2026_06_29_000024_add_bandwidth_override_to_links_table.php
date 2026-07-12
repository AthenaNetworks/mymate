<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            // Per-direction bandwidth override. The capacity that
            // drives utilisation lives on the link now (the circuit/service), not the
            // physical interface. Oriented A->B / B->A; nullable -> falls back to the
            // derived speed (the slower of the two end interfaces). bw_ba falls back to
            // bw_ab, so a symmetric circuit only needs bw_ab set. Asymmetric (500dn/50up)
            // sets the two independently.
            $table->unsignedInteger('bw_ab_mbps')->nullable()->after('b_interface_id');
            $table->unsignedInteger('bw_ba_mbps')->nullable()->after('bw_ab_mbps');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table): void {
            $table->dropColumn(['bw_ab_mbps', 'bw_ba_mbps']);
        });
    }
};
