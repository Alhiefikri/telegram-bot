<?php

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'order_id' => Order::factory(), 'type' => InventoryMovementType::Adjustment, 'quantity' => 1, 'notes' => 'factory adjustment', 'created_at' => now()];
    }
}
