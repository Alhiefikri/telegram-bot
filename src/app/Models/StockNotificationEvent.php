<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['inventory_movement_id', 'kind', 'recipients_snapshotted_at', 'materialization_cursor'])]
class StockNotificationEvent extends Model
{
    protected function casts(): array
    {
        return [
            'recipients_snapshotted_at' => 'datetime',
            'materialization_cursor' => 'integer',
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
