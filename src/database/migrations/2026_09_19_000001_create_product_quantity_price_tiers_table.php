<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_quantity_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minimum_quantity');
            $table->unsignedBigInteger('price_amount');
            $table->unsignedBigInteger('usdt_price_amount')->nullable();
            $table->timestamps();
            $table->unique(
                ['product_id', 'minimum_quantity'],
                'product_quantity_price_tiers_product_minimum_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_quantity_price_tiers ADD CONSTRAINT product_quantity_price_tiers_minimum_quantity_supported CHECK (minimum_quantity BETWEEN 2 AND 20)');
            DB::statement('ALTER TABLE product_quantity_price_tiers ADD CONSTRAINT product_quantity_price_tiers_price_amount_positive CHECK (price_amount > 0)');
            DB::statement('ALTER TABLE product_quantity_price_tiers ADD CONSTRAINT product_quantity_price_tiers_usdt_price_amount_positive CHECK (usdt_price_amount IS NULL OR usdt_price_amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_quantity_price_tiers');
    }
};
