<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity_price_tier_minimum')->nullable()->after('quantity');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_price_tier_minimum_supported CHECK (quantity_price_tier_minimum IS NULL OR quantity_price_tier_minimum BETWEEN 2 AND 20)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_quantity_price_tier_minimum_supported');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_price_tier_minimum');
        });
    }
};
