<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_account_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_stock_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 128);
            $table->string('provider_state', 24);
            $table->timestamp('refund_deadline');
            $table->unsignedBigInteger('cost_idr');
            $table->unsignedBigInteger('retail_amount');
            $table->string('retail_currency', 4);
            $table->unsignedInteger('unit_number');
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id'], 'supplier_account_identity_unique');
            $table->index(['order_id', 'id']);
        });
        Schema::table('suppliers', fn (Blueprint $table) => $table->unique(['provider', 'external_account_id'], 'supplier_connection_account_unique'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_account_units ADD CONSTRAINT supplier_unit_amount_guard CHECK (cost_idr > 0 AND retail_amount >= 0 AND unit_number > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropUnique('supplier_connection_account_unique'));
        Schema::dropIfExists('supplier_account_units');
    }
};
