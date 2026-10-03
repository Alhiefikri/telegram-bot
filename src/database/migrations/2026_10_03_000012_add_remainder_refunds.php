<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_refunds DROP CONSTRAINT order_refund_origin_guard');
            DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refund_origin_guard CHECK ((warranty_claim_id IS NOT NULL AND supplier_account_unit_id IS NOT NULL AND supplier_fulfillment_id IS NULL AND missing_quantity IS NULL) OR (warranty_claim_id IS NULL AND supplier_account_unit_id IS NULL AND supplier_fulfillment_id IS NOT NULL AND missing_quantity IS NOT NULL AND missing_quantity > 0) OR (warranty_claim_id IS NULL AND supplier_account_unit_id IS NULL AND supplier_fulfillment_id IS NULL AND missing_quantity IS NULL))');
        }
        DB::statement('CREATE UNIQUE INDEX order_refunds_remainder_unique ON order_refunds (payment_id) WHERE warranty_claim_id IS NULL AND supplier_account_unit_id IS NULL AND supplier_fulfillment_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS order_refunds_remainder_unique');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_refunds DROP CONSTRAINT order_refund_origin_guard');
            DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refund_origin_guard CHECK ((warranty_claim_id IS NOT NULL AND supplier_account_unit_id IS NOT NULL AND supplier_fulfillment_id IS NULL AND missing_quantity IS NULL) OR (warranty_claim_id IS NULL AND supplier_account_unit_id IS NULL AND supplier_fulfillment_id IS NOT NULL AND missing_quantity IS NOT NULL AND missing_quantity > 0))');
        }
    }
};
