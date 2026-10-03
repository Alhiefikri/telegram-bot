<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Hidden(['proof_path', 'proof_digest', 'note', 'provider_chat_url', 'claim_token', 'decision_history', 'provider_decision', 'replacementPurchase', 'replacement_purchase'])]
final class WarrantyClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'note' => 'encrypted', 'provider_chat_url' => 'encrypted', 'decision_history' => 'array',
            'refund_deadline' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'replacement_next_attempt_at' => 'immutable_datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SupplierAccountUnit::class, 'supplier_account_unit_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function replacementPurchase(): HasOne
    {
        return $this->hasOne(SupplierPurchase::class);
    }
}
