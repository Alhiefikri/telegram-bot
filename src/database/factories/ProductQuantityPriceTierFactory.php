<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductQuantityPriceTier> */
class ProductQuantityPriceTierFactory extends Factory
{
    protected $model = ProductQuantityPriceTier::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'minimum_quantity' => 2,
            'price_amount' => 5000,
            'usdt_price_amount' => null,
        ];
    }
}
