<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S02-fix-A (#638): a base dollar price of `0` is not a usable dollar rail, so it
 * must not authorize a reseller dollar override. The original constraint only
 * rejected an absent base price, which let `usdt_price_amount = 0` plus a
 * positive `reseller_usdt_price_amount` quote and sell through USD while the
 * retail dollar price could never be charged.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'products_reseller_usdt_price_amount_requires_base_price';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The old constraint is dropped first so the repair below is allowed to
        // touch the rows it still covers; the whole migration is one transaction,
        // so a failure leaves the original constraint in place.
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

            $removed = DB::table('products')
                ->whereNotNull('reseller_usdt_price_amount')
                ->where(fn ($query) => $query->whereNull('usdt_price_amount')->orWhere('usdt_price_amount', '<=', 0))
                ->update(['reseller_usdt_price_amount' => null]);

            DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (reseller_usdt_price_amount IS NULL OR (usdt_price_amount IS NOT NULL AND usdt_price_amount > 0))');

            if ($removed === 0) {
                return;
            }

            // Only the unusable override is cleared: base prices, retail data and
            // audit history stay untouched, and the removal is recorded.
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'product.reseller_usdt_override_removed',
                'subject_type' => 'App\\Models\\Product',
                'subject_id' => null,
                'old_values' => null,
                'new_values' => json_encode(['reason' => 'unusable_base_usdt_price', 'products' => $removed], JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement('ALTER TABLE products ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (reseller_usdt_price_amount IS NULL OR usdt_price_amount IS NOT NULL)');
    }
};
