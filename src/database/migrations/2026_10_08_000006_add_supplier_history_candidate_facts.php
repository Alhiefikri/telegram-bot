<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_order_history_reconciliations', function (Blueprint $table): void {
            $table->text('candidate_facts')->nullable();
            $table->string('coverage_kind', 32)->nullable();
            $table->string('purchase_version', 32)->nullable();
            $table->string('purchase_claim_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_order_history_reconciliations', function (Blueprint $table): void {
            $table->dropColumn(['candidate_facts', 'coverage_kind', 'purchase_version', 'purchase_claim_token']);
        });
    }
};
