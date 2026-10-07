<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_purchase_evidence', function (Blueprint $table): void {
            $table->foreignId('history_reconciliation_id')->nullable()
                ->constrained('supplier_order_history_reconciliations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_purchase_evidence', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('history_reconciliation_id');
        });
    }
};
