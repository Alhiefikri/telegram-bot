<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return ['order_id' => Order::factory(), 'product_id' => Product::factory(), 'product_name' => fake()->words(2, true), 'delivery_note' => null, 'delivery_note_snapshotted' => false, 'usage_guide_title' => null, 'usage_guide_url' => null, 'usage_guide_snapshotted' => false, 'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000];
    }
}
