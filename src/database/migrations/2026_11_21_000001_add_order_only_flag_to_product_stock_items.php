<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->boolean('is_order_only')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('product_stock_items')->where('is_order_only', true)->exists()) {
            throw new RuntimeException('Order-only credentials must not become catalog stock.');
        }

        Schema::table('product_stock_items', function (Blueprint $table): void {
            $table->dropColumn('is_order_only');
        });
    }
};
