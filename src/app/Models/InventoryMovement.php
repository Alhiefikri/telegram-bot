<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'order_id', 'type', 'quantity', 'available_before', 'notes', 'stock_notification_started_at', 'stock_notification_sent_at', 'stock_notification_claim_token', 'created_at'])]
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'quantity' => 'integer', 'available_before' => 'integer', 'stock_notification_started_at' => 'datetime', 'stock_notification_sent_at' => 'datetime', 'created_at' => 'datetime'];
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
