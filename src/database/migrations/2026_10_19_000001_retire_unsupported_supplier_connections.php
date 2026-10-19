<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('migrations')
            ->where('migration', 'like', '2026_10_03_000001_%')
            ->update(['migration' => '2026_10_03_000001_add_supplier_account_metadata']);
        $supported = ['prodseller', 'csxstore', 'arrs', 'warzone', 'aivault', 'nystore', 'toolorax', 'canboso', 'nastele'];
        $supplierIds = DB::table('suppliers')->whereNotIn('provider', $supported)->pluck('id');
        if ($supplierIds->isNotEmpty()) {
            $catalogIds = DB::table('supplier_catalog_items')->whereIn('supplier_id', $supplierIds)->select('id');
            $productIds = DB::table('product_supplier_sources')->whereIn('supplier_catalog_item_id', $catalogIds)->pluck('product_id');
            DB::table('products')->whereIn('id', $productIds)->update([
                'supplier_available_stock' => 0,
                'supplier_available_until' => null,
            ]);
            DB::table('product_supplier_sources')->whereIn('supplier_catalog_item_id', $catalogIds)->update(['is_active' => false]);
            DB::table('suppliers')->whereIn('id', $supplierIds)->update([
                'is_active' => false,
                'synced_at' => null,
                'sync_expires_at' => null,
                'sync_token' => null,
                'sync_lease_expires_at' => null,
                'version' => DB::raw('version + 1'),
            ]);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers DROP CONSTRAINT supplier_money_guard');
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT supplier_money_guard CHECK (reserved_balance_micros >= 0 AND exchange_rate_idr > 0 AND version > 0 AND currency IN ('USD', 'USDT', 'IDR', 'VND') AND (provider <> 'nastele' OR currency = 'VND') AND (currency <> 'VND' OR provider = 'nastele'))");
        }
        DB::table('telegram_outbound_effects')
            ->whereIn('effect_type', ['order.warranty_notification', 'order.warranty_deadline_reminder'])
            ->whereIn('state', ['pending', 'sending', 'failed'])
            ->update([
                'state' => 'manual_review',
                'manual_review_at' => now(),
                'next_attempt_at' => null,
                'lease_until' => null,
                'claim_token' => null,
                'last_error_code' => 'supplier_integration_retired',
            ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Retired supplier connections require explicit operator review before reactivation.');
    }
};
