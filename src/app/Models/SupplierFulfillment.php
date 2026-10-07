<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['order_id', 'order_item_id', 'quantity', 'state', 'maximum_cost_idr', 'sources_snapshot', 'reserved_supplier_id', 'reserved_catalog_item_id', 'reserved_cost_micros', 'reserved_quantity', 'current_purchase_id', 'delivered_at', 'next_attempt_at', 'last_error'])]
#[Hidden(['sources_snapshot', 'health_review_episode'])]
class SupplierFulfillment extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $fulfillment): void {
            if ($fulfillment->isDirty('state')) {
                if ($fulfillment->state === 'review' && $fulfillment->health_review_episode === null) {
                    $fulfillment->health_review_episode = (string) Str::uuid();
                } elseif (in_array($fulfillment->state, ['delivered', 'cancelled'], true)) {
                    $fulfillment->health_review_episode = null;
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['order_item_id' => 'integer', 'quantity' => 'integer', 'refunded_quantity' => 'integer', 'maximum_cost_idr' => 'integer', 'sources_snapshot' => 'encrypted:array', 'reserved_supplier_id' => 'integer', 'reserved_catalog_item_id' => 'integer', 'reserved_cost_micros' => 'integer', 'reserved_quantity' => 'integer', 'current_purchase_id' => 'integer', 'delivered_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(SupplierPurchase::class);
    }

    public function currentPurchase(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchase::class, 'current_purchase_id');
    }

    public function manualHandoffs(): HasMany
    {
        return $this->hasMany(SupplierManualHandoff::class);
    }

    public function reservedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'reserved_supplier_id');
    }
}
