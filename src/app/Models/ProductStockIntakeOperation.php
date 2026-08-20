<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'operation_key',
    'product_id',
    'inventory_movement_id',
    'actor_id',
    'payload_hash',
    'mode',
    'item_count',
    'stock_before',
    'stock_after',
    'available_before',
    'available_after',
    'ip_address',
])]
class ProductStockIntakeOperation extends Model
{
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'inventory_movement_id' => 'integer',
            'actor_id' => 'integer',
            'item_count' => 'integer',
            'stock_before' => 'integer',
            'stock_after' => 'integer',
            'available_before' => 'integer',
            'available_after' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function movement()
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
