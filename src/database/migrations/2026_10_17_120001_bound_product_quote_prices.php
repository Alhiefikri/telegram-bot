<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PRICE_COLUMNS = [
        'products' => ['price_amount', 'usdt_price_amount', 'reseller_price_amount', 'reseller_usdt_price_amount'],
        'product_quantity_price_tiers' => ['price_amount', 'usdt_price_amount'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // NOT VALID preserves legacy outliers without rewriting economic data.
        // PostgreSQL still checks every newly inserted or updated row.
        foreach (self::PRICE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_quote_bound CHECK ({$column} <= 92233720368547758) NOT VALID");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PRICE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_quote_bound");
            }
        }
    }
};
