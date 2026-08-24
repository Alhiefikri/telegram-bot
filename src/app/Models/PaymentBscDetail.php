<?php

namespace App\Models;

use Database\Factories\PaymentBscDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payment_id',
    'receiver_id',
    'receiver_address',
    'chain_id',
    'token_contract',
    'token_decimals',
    'base_amount_micro',
    'payable_amount_micro',
    'expected_token_units',
    'verification_status',
    'tx_submission_version',
    'tx_attempts',
    'verification_grace_until',
    'last_verified_at',
    'current_tx_hash',
    'current_confirmations',
    'current_block_number',
    'current_from_address',
    'evidence_payload',
])]
class PaymentBscDetail extends Model
{
    public const STATUS_AWAITING_TRANSFER = 'awaiting_transfer';

    protected $primaryKey = 'payment_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @use HasFactory<PaymentBscDetailFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'receiver_id' => 'integer',
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'base_amount_micro' => 'integer',
            'payable_amount_micro' => 'integer',
            'expected_token_units' => 'string',
            'tx_submission_version' => 'integer',
            'tx_attempts' => 'integer',
            'verification_grace_until' => 'datetime',
            'last_verified_at' => 'datetime',
            'current_confirmations' => 'integer',
            'current_block_number' => 'integer',
            'evidence_payload' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(BscPaymentReceiver::class, 'receiver_id');
    }
}
