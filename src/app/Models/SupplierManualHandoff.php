<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_fulfillment_id', 'actor_user_id', 'idempotency_key', 'quantity', 'note', 'handed_off_at'])]
final class SupplierManualHandoff extends Model
{
    protected function casts(): array
    {
        return [
            'supplier_fulfillment_id' => 'integer',
            'actor_user_id' => 'integer',
            'idempotency_key' => 'string',
            'quantity' => 'integer',
            'handed_off_at' => 'immutable_datetime',
        ];
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(SupplierFulfillment::class, 'supplier_fulfillment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
