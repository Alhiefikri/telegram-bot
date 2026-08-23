<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'order_id', 'type', 'quantity', 'stock_before', 'became_unavailable', 'available_before', 'available_after', 'notes', 'stock_notification_sent_at', 'created_at'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'quantity' => 'integer', 'stock_before' => 'integer', 'became_unavailable' => 'boolean', 'available_before' => 'integer', 'available_after' => 'integer', 'stock_notification_sent_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
