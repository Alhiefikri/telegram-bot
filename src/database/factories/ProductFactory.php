<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['category_id' => null, 'name' => fake()->words(3, true), 'description' => fake()->sentence(), 'price_amount' => 10000, 'currency' => 'IDR', 'stock' => 10, 'reserved_stock' => 0, 'image_path' => null, 'is_active' => true];
    }
}
