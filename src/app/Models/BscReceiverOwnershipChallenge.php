<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'receiver_id',
    'user_id',
    'wallet_address',
    'chain_id',
    'purpose',
    'message',
    'message_hash',
    'nonce_hash',
    'expires_at',
    'used_at',
])]
class BscReceiverOwnershipChallenge extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(BscPaymentReceiver::class, 'receiver_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at?->isFuture() === true;
    }
}
