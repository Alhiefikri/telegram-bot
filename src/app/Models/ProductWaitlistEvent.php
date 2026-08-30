<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'inventory_movement_id',
    'product_id',
    'available_stock',
    'status',
    'materialization_cursor',
    'materialized_at',
    'skipped_at',
])]
class ProductWaitlistEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return [
            'available_stock' => 'integer',
            'materialization_cursor' => 'integer',
            'materialized_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ProductWaitlistNotification::class, 'product_waitlist_event_id');
    }
}
