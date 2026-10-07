<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_purchase_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_purchase_id')->constrained()->restrictOnDelete();
            $table->uuid('claim_token')->unique();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome')->default('started');
            $table->string('error_code')->nullable();
            $table->index(['supplier_purchase_id', 'id']);
        });
        Schema::table('supplier_order_history_reconciliations', function (Blueprint $table): void {
            $table->unsignedBigInteger('actor_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // System-authored history remains valid evidence after rollback; retain nullable actor.
        Schema::dropIfExists('supplier_purchase_dispatches');
    }
};
