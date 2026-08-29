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
        return ['category_id' => null, 'name' => fake()->words(3, true), 'description' => fake()->sentence(), 'product_type' => null, 'access_duration' => null, 'region' => null, 'account_type' => null, 'device_limit' => null, 'included_items' => null, 'limitations' => null, 'fulfillment_expectation' => null, 'warranty_policy' => null, 'support_policy' => null, 'delivery_note' => null, 'usage_guide_title' => null, 'usage_guide_url' => null, 'price_amount' => 10000, 'currency' => 'IDR', 'stock' => 10, 'reserved_stock' => 0, 'image_path' => null, 'is_active' => true, 'featured_rank' => null];
    }
}
