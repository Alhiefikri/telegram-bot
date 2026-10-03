<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_account_unit_id')->unique()->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('warranty_claim_id')->unique()->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 4);
            $table->string('state', 24)->default('requested');
            $table->text('external_reference')->nullable();
            $table->string('external_reference_hash', 64)->unique()->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'state']);
        });
        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->foreignUuid('refund_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->index(['customer_balance_account_id', 'refund_order_id', 'kind'], 'balance_order_partial_refunds');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE order_refunds ADD CONSTRAINT order_refund_amount_guard CHECK (amount > 0 AND currency IN ('IDR', 'USDT'))");
            DB::statement("ALTER TABLE store_balance_entries ADD CONSTRAINT balance_partial_refund_source_guard CHECK ((source_type = 'order_refund' AND kind = 'refund_credit' AND refund_order_id IS NOT NULL) OR (source_type <> 'order_refund' AND refund_order_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE store_balance_entries DROP CONSTRAINT balance_partial_refund_source_guard');
        }
        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->dropIndex('balance_order_partial_refunds');
            $table->dropConstrainedForeignId('refund_order_id');
        });
        Schema::dropIfExists('order_refunds');
    }
};
