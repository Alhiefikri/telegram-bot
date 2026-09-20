<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'inventory_movement_id',
    'kind',
    'recipients_snapshotted_at',
    'materialization_cursor',
    'tier_promotion_snapshotted_at',
    'promoted_tier_minimum',
    'promoted_tier_price_amount',
    'promoted_tier_usdt_price_amount',
])]
class StockNotificationEvent extends Model
{
    protected function casts(): array
    {
        return [
            'recipients_snapshotted_at' => 'datetime',
            'materialization_cursor' => 'integer',
            'tier_promotion_snapshotted_at' => 'datetime',
            'promoted_tier_minimum' => 'integer',
            'promoted_tier_price_amount' => 'integer',
            'promoted_tier_usdt_price_amount' => 'integer',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(StockNotificationRecipient::class);
    }
}
