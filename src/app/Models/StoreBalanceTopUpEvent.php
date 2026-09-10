<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'store_balance_top_up_id',
    'provider',
    'event_type',
    'merchant_ref',
    'provider_reference',
    'provider_status',
    'raw_payload',
    'idempotency_key',
    'received_count',
    'received_at',
    'last_received_at',
    'processed_at',
])]
class StoreBalanceTopUpEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'store_balance_top_up_id' => 'integer',
            'raw_payload' => 'array',
            'received_count' => 'integer',
            'received_at' => 'datetime',
            'last_received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function topUp()
    {
        return $this->belongsTo(StoreBalanceTopUp::class, 'store_balance_top_up_id');
    }
}
