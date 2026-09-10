<?php

namespace App\Models;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceTopUpStatus;
use App\Support\PaymentProvider;
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
    'merchant_ref',
    'idempotency_key',
    'currency',
    'amount',
    'status',
    'provider_reference',
    'provider_payload',
    'provider_qris_url',
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
    'paid_at',
    'settled_at',
])]
class StoreBalanceTopUp extends Model
{
    protected $hidden = [
        'provider_qris_url',
        'provider_signature',
    ];

    protected function casts(): array
    {
        return [
            'telegram_navigation_root_id' => 'integer',
            'canonical_message_id' => 'integer',
            'amount' => 'integer',
            'provider_fee' => 'integer',
            'provider_total_payment' => 'integer',
            'status' => StoreBalanceTopUpStatus::class,
            'provider_payload' => 'array',
            'provider_qris_url' => 'encrypted',
            'provider_signature' => 'encrypted',
            'provider_created_at' => 'datetime',
            'provider_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'provider_create_attempted_at' => 'datetime',
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

    public function outboundEffects()
    {
        return $this->morphMany(TelegramOutboundEffect::class, 'source');
    }

    public function isPayable(): bool
    {
        return $this->status === StoreBalanceTopUpStatus::Payable
            && strtolower(trim((string) $this->provider)) === PaymentProvider::KLIKQRIS
            && strtoupper(trim((string) $this->currency)) === 'IDR'
            && strtolower(trim((string) $this->provider_payment_method)) === 'qris'
            && $this->amount > 0
            && $this->provider_fee !== null
            && $this->provider_fee >= 0
            && $this->provider_total_payment !== null
            && $this->provider_total_payment >= $this->amount
            && filled($this->provider_qris_url)
            && $this->provider_expires_at?->isFuture() === true
            && $this->expires_at?->isFuture() === true;
    }

    public function canRetryCreate(): bool
    {
        return $this->status === StoreBalanceTopUpStatus::Failed
            && $this->provider_create_state === 'create_failed';
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
