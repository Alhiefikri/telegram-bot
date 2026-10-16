<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('reseller_price_amount')->nullable()->after('usdt_price_amount');
            $table->unsignedBigInteger('reseller_usdt_price_amount')->nullable()->after('reseller_price_amount');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_reseller_price_amount_positive CHECK (reseller_price_amount IS NULL OR reseller_price_amount > 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_reseller_usdt_price_amount_positive CHECK (reseller_usdt_price_amount IS NULL OR reseller_usdt_price_amount > 0)');
            // A reseller dollar override can never manufacture dollar eligibility.
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_reseller_usdt_price_amount_requires_base_price CHECK (reseller_usdt_price_amount IS NULL OR usdt_price_amount IS NOT NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_reseller_usdt_price_amount_requires_base_price');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_reseller_usdt_price_amount_positive');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_reseller_price_amount_positive');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['reseller_price_amount', 'reseller_usdt_price_amount']);
        });
    }
};
