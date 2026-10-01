<?php

namespace App\Models;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'customer_id',
    'store_balance_funding_intent_id',
    'telegram_navigation_root_id',
    'chat_id',
    'canonical_message_id',
    'locale',
    'provider',
    'payment_setting_id',
    'payment_provider_version',
    'merchant_ref',
    'idempotency_key',
    'currency',
    'amount',
    'status',
    'provider_reference',
    'provider_payload',
    'provider_qris_url',
    'provider_qris_payload',
    'provider_fee',
    'provider_total_payment',
    'provider_payment_method',
    'provider_created_at',
    'provider_expires_at',
    'expires_at',
    'provider_signature',
    'provider_create_state',
    'provider_create_attempted_at',
    'provider_create_last_error',
    'provider_create_initial_error',
    'payment_proof_file_id',
    'payment_proof_submitted_at',
    'payment_proof_admin_chat_id',
    'payment_proof_forwarded_at',
    'paid_at',
    'settled_at',
])]
class StoreBalanceTopUp extends Model
{
    protected $hidden = [
        'provider_qris_url',
        'provider_qris_payload',
        'provider_signature',
    ];

    protected function casts(): array
    {
        return [
            'telegram_navigation_root_id' => 'integer',
            'canonical_message_id' => 'integer',
            'payment_setting_id' => 'integer',
            'payment_provider_version' => 'integer',
            'amount' => 'integer',
            'provider_fee' => 'integer',
            'provider_total_payment' => 'integer',
            'status' => StoreBalanceTopUpStatus::class,
            'provider_payload' => 'array',
            'provider_qris_url' => 'encrypted',
            'provider_qris_payload' => 'encrypted',
            'provider_signature' => 'encrypted',
            'provider_created_at' => 'datetime',
            'provider_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'provider_create_attempted_at' => 'datetime',
            'payment_proof_submitted_at' => 'datetime',
            'payment_proof_forwarded_at' => 'datetime',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function fundingIntent()
    {
        return $this->belongsTo(StoreBalanceFundingIntent::class, 'store_balance_funding_intent_id');
    }

    public function navigationRoot()
    {
        return $this->belongsTo(TelegramNavigationRoot::class, 'telegram_navigation_root_id');
    }

    public function events()
    {
        return $this->hasMany(StoreBalanceTopUpEvent::class);
    }

    public function creditEntry()
    {
        return $this->hasOne(StoreBalanceEntry::class, 'source_id', 'id')
            ->where('source_type', 'balance_top_up')
            ->where('kind', StoreBalanceEntryKind::TopUpCredit->value);
    }

    public function compensation()
    {
        return $this->hasOne(StoreBalanceCompensation::class, 'store_balance_top_up_id');
    }

    public function isEligibleForOwnerCompensation(): bool
    {
        if ($this->status === StoreBalanceTopUpStatus::Compensated
            || $this->status === StoreBalanceTopUpStatus::Paid
            || $this->compensation !== null) {
            return false;
        }

        if (in_array($this->status, [
            StoreBalanceTopUpStatus::Expired,
            StoreBalanceTopUpStatus::RecoveryPending,
            StoreBalanceTopUpStatus::InvalidProviderResult,
        ], true)) {
            return true;
        }

        return $this->status === StoreBalanceTopUpStatus::Failed
            && ($this->provider_created_at !== null || filled($this->provider_reference));
    }

    public function isEligibleForOwnerAttention(): bool
    {
        if ($this->status === StoreBalanceTopUpStatus::Paid
            || $this->status === StoreBalanceTopUpStatus::Compensated
            || $this->compensation !== null) {
            return false;
        }

        if (in_array($this->status, [
            StoreBalanceTopUpStatus::RecoveryPending,
            StoreBalanceTopUpStatus::InvalidProviderResult,
        ], true)) {
            return true;
        }

        return $this->status === StoreBalanceTopUpStatus::Expired
            && filled($this->payment_proof_file_id);
    }

    public function outboundEffects()
    {
        return $this->morphMany(TelegramOutboundEffect::class, 'source');
    }

    public function isPayable(): bool
    {
        return $this->status === StoreBalanceTopUpStatus::Payable
            && strtoupper(trim((string) $this->currency)) === 'IDR'
            && strtoupper(trim((string) $this->provider_payment_method)) === 'QRIS'
            && $this->amount > 0
            && $this->provider_fee !== null
            && $this->provider_fee >= 0
            && $this->provider_total_payment !== null
            && $this->provider_total_payment >= $this->amount
            && $this->provider_expires_at?->isFuture() === true
            && $this->expires_at?->isFuture() === true;
    }

    public function canRetryCreate(): bool
    {
        return $this->status === StoreBalanceTopUpStatus::Failed
            && in_array($this->provider_create_state, ['create_failed', 'provider_unavailable'], true);
    }

    public function hasPaymentProof(): bool
    {
        return filled($this->payment_proof_file_id) && $this->payment_proof_submitted_at !== null;
    }

    public function owesManualProof(): bool
    {
        return filled($this->payment_proof_admin_chat_id) && $this->payment_proof_forwarded_at === null;
    }

    public function isRecoveryPending(): bool
    {
        return in_array($this->provider_create_state, [
            'creating',
            'outcome_unknown',
            'recovery_unconfirmed',
        ], true);
    }

    public function providerSignature(): ?string
    {
        $signature = trim((string) $this->provider_signature);

        return $signature === '' ? null : $signature;
    }
}
