<?php

namespace App\Models;

use Database\Factories\BscPaymentTransferEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id',
    'tx_hash',
    'token_contract',
    'receiver_address',
    'from_address',
    'amount_units',
    'block_number',
    'block_hash',
    'block_timestamp',
    'receipt_status',
    'log_index',
    'confirmations',
    'verification_status',
    'evidence_payload',
    'first_seen_at',
    'last_verified_at',
])]
class BscPaymentTransferEvidence extends Model
{
    /** @use HasFactory<BscPaymentTransferEvidenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_units' => 'string',
            'block_number' => 'integer',
            'block_timestamp' => 'datetime',
            'log_index' => 'integer',
            'confirmations' => 'integer',
            'evidence_payload' => 'array',
            'first_seen_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
