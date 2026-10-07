<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['supplier_fulfillment_id', 'supplier_id', 'supplier_catalog_item_id', 'reference', 'supplier_order_id', 'quantity', 'observed_quantity', 'expected_cost_micros', 'actual_cost_micros', 'exchange_rate_idr', 'state', 'claim_token', 'lease_expires_at', 'dispatched_at', 'replay_count', 'next_attempt_at', 'delivery_payload', 'trusted_delivery_payload', 'last_error', 'committed_at'])]
#[Hidden(['delivery_payload', 'trusted_delivery_payload', 'claim_token', 'account_units', 'warranty_claim_id', 'warranty_hold_active', 'warranty_reserved_cost_micros', 'credential_fingerprint', 'health_review_episode'])]
class SupplierPurchase extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $purchase): void {
            if ($purchase->isDirty('state')) {
                if (in_array($purchase->state, ['review', 'ambiguous'], true) && $purchase->health_review_episode === null) {
                    $purchase->health_review_episode = (string) Str::uuid();
                } elseif (in_array($purchase->state, ['delivered', 'settled', 'rejected', 'cancelled'], true)) {
                    $purchase->health_review_episode = null;
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['supplier_fulfillment_id' => 'integer', 'supplier_id' => 'integer', 'supplier_catalog_item_id' => 'integer', 'quantity' => 'integer', 'observed_quantity' => 'integer', 'expected_cost_micros' => 'integer', 'actual_cost_micros' => 'integer', 'exchange_rate_idr' => 'integer', 'replay_count' => 'integer', 'lease_expires_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'delivery_payload' => 'encrypted:array', 'trusted_delivery_payload' => 'encrypted:array', 'account_units' => 'encrypted:array', 'committed_at' => 'immutable_datetime'];
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(SupplierFulfillment::class, 'supplier_fulfillment_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogItem::class, 'supplier_catalog_item_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(SupplierPurchaseEvidence::class);
    }

    public function historyReconciliations(): HasMany
    {
        return $this->hasMany(SupplierOrderHistoryReconciliation::class);
    }

    public function latestHistoryReconciliation(): HasOne
    {
        return $this->hasOne(SupplierOrderHistoryReconciliation::class)->latestOfMany();
    }
}
