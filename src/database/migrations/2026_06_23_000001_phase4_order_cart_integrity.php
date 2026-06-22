<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('cart_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
            $table->unique('cart_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unique(['order_id', 'product_id', 'type'], 'inventory_movements_order_product_type_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_nonnegative CHECK (stock >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_reserved_stock_nonnegative CHECK (reserved_stock >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_reserved_stock_not_above_stock CHECK (reserved_stock <= stock)');
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_nonnegative CHECK (unit_price >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_subtotal_nonnegative CHECK (subtotal >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_amount_nonnegative CHECK (total_amount >= 0)');
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_nonnegative CHECK (amount >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_nonnegative');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_amount_nonnegative');
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_subtotal_nonnegative');
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_unit_price_nonnegative');
            DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_quantity_positive');
            DB::statement('ALTER TABLE cart_items DROP CONSTRAINT IF EXISTS cart_items_quantity_positive');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_reserved_stock_not_above_stock');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_reserved_stock_nonnegative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_stock_nonnegative');
        }

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique('inventory_movements_order_product_type_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['cart_id']);
            $table->dropConstrainedForeignId('cart_id');
        });
    }
};
