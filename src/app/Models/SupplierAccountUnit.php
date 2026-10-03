<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['supplier_id', 'supplier_purchase_id', 'external_id', 'cost_idr', 'supplier', 'purchase', 'confirmation_token'])]
final class SupplierAccountUnit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['refund_deadline' => 'immutable_datetime', 'cost_idr' => 'integer', 'retail_amount' => 'integer', 'unit_number' => 'integer', 'confirmation_lease_until' => 'immutable_datetime', 'confirmation_requested_at' => 'immutable_datetime', 'confirmation_next_attempt_at' => 'immutable_datetime', 'reminder_materialized_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(ProductStockItem::class, 'product_stock_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchase::class, 'supplier_purchase_id');
    }
}
