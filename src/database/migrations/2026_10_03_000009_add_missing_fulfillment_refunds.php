<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table): void {
            $table->foreignId('supplier_fulfillment_id')->unique()->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('missing_quantity')->nullable();
        });
        Schema::table('supplier_fulfillments', fn (Blueprint $table) => $table->unsignedInteger('refunded_quantity')->default(0));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_fulfillments ADD CONSTRAINT supplier_refunded_quantity_guard CHECK (refunded_quantity BETWEEN 0 AND quantity)');
            DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refund_origin_guard CHECK ((warranty_claim_id IS NOT NULL AND supplier_account_unit_id IS NOT NULL AND supplier_fulfillment_id IS NULL AND missing_quantity IS NULL) OR (warranty_claim_id IS NULL AND supplier_account_unit_id IS NULL AND supplier_fulfillment_id IS NOT NULL AND missing_quantity IS NOT NULL AND missing_quantity > 0))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_fulfillments DROP CONSTRAINT supplier_refunded_quantity_guard');
            DB::statement('ALTER TABLE order_refunds DROP CONSTRAINT order_refund_origin_guard');
        }
        Schema::table('supplier_fulfillments', fn (Blueprint $table) => $table->dropColumn('refunded_quantity'));
        Schema::table('order_refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_fulfillment_id');
            $table->dropColumn('missing_quantity');
        });
    }
};
