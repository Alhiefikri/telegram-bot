<?php

namespace App\Models;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Services\KlikQris\KlikQrisAmount;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Support\PaymentProvider;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'order_id',
    'provider',
    'merchant_ref',
    'provider_reference',
    'provider_payload',
    'klikqris_transaction_signature',
    'provider_fee',
    'provider_total_payment',
    'provider_payment_method',
    'provider_created_at',
    'provider_expires_at',
    'provider_payment_number',
    'provider_payment_number_hash',
    'provider_create_state',
    'provider_create_attempted_at',
    'provider_create_last_error',
    'method',
    'binance_auto_verification_enabled',
    'binance_verification_mode',
    'binance_receiver_type',
    'binance_receiver_value',
    'binance_order_id',
    'binance_order_id_attempts',
    'binance_order_id_submission_version',
    'binance_order_id_submitted_at',
    'binance_verification_grace_until',
    'binance_verification_status',
    'binance_last_verified_at',
    'amount',
    'currency',
    'status',
    'paid_at',
    'expired_at',
    'payment_proof_file_id', 'payment_proof_submitted_at', 'payment_proof_admin_chat_id', 'payment_proof_forwarded_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids;

    protected $hidden = [
        'klikqris_transaction_signature',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'binance_auto_verification_enabled' => 'boolean',
            'binance_order_id_submission_version' => 'integer',
            'binance_order_id_attempts' => 'integer',
            'binance_order_id_submitted_at' => 'datetime',
            'binance_verification_grace_until' => 'datetime',
            'binance_last_verified_at' => 'datetime',
            'provider_payload' => 'array',
            'provider_fee' => 'integer',
            'provider_total_payment' => 'integer',
            'provider_created_at' => 'datetime',
            'provider_expires_at' => 'datetime',
            'provider_payment_number' => 'encrypted',
            'klikqris_transaction_signature' => 'encrypted',
            'provider_create_attempted_at' => 'datetime',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'payment_proof_file_id' => 'encrypted',
            'payment_proof_submitted_at' => 'datetime',
            'payment_proof_forwarded_at' => 'datetime',
        ];
    }

    public function providerCancellationAttempt()
    {
        return $this->hasOne(ProviderCancellationAttempt::class);
    }

    public function bscDetail()
    {
        return $this->hasOne(PaymentBscDetail::class);
    }

    public function paymentBscDetail()
    {
        return $this->bscDetail();
    }

    public function bscPaymentDetail()
    {
        return $this->bscDetail();
    }

    public function bscTransferEvidence()
    {
        return $this->hasMany(BscPaymentTransferEvidence::class, 'payment_id');
    }

    public function bscTransferEvidences()
    {
        return $this->bscTransferEvidence();
    }

    protected function paymentMethodLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->providerName() === PaymentProvider::PAKASIR => 'QRIS',
                default => $this->localPaymentMethodLabel(),
            },
        );
    }

    public function isNativeKlikQris(): bool
    {
        return $this->providerName() === PaymentProvider::KLIKQRIS
            && $this->providerPaymentMethodName() === 'qris'
            && filled($this->klikQrisQrisUrl());
    }

    public function isPayableNativeKlikQris(): bool
    {
        return $this->status === PaymentStatus::Pending
            && $this->isNativeKlikQris()
            && $this->provider_total_payment !== null
            && $this->klikQrisTotalAmountDisplay() !== null
            && $this->provider_total_payment >= $this->amount
            && $this->provider_expires_at !== null
            && $this->provider_expires_at->isFuture();
    }

    public function klikQrisQrisUrl(?KlikQrisEnvironment $environment = null): ?string
    {
        $payload = is_array($this->provider_payload) ? $this->provider_payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $url = trim((string) ($data['qris_url'] ?? ''));

        return KlikQrisTransaction::isAllowedQrisUrl(
            $url,
            environment: $environment ?? KlikQrisEnvironment::runtime(),
        ) ? $url : null;
    }

    public function klikQrisTotalAmountDisplay(): ?string
    {
        if ($this->provider_total_payment === null) {
            return null;
        }

        $payload = $this->provider_payload;
        if (! is_array($payload)) {
            return null;
        }

        $data = $payload;
        if (array_key_exists('data', $payload)) {
            if (! is_array($payload['data'])) {
                return null;
            }

            $data = $payload['data'];
        }

        try {
            $totalAmount = KlikQrisAmount::from($data['total_amount'] ?? null);
        } catch (\UnexpectedValueException) {
            return null;
        }

        if ($totalAmount->rupiah() !== (int) $this->provider_total_payment) {
            return null;
        }

        return 'IDR '.$totalAmount->display();
    }

    private function providerName(): string
    {
        return strtolower(trim((string) $this->provider));
    }

    private function providerPaymentMethodName(): string
    {
        return strtolower(trim((string) $this->provider_payment_method));
    }

    private function localPaymentMethodLabel(): string
    {
        $method = trim((string) $this->method);
        if ($method !== '') {
            return $method;
        }

        $provider = trim((string) $this->provider);

        return $provider !== '' ? $provider : 'unknown';
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentEvents()
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function manualFulfillmentReview()
    {
        return $this->hasOne(ManualFulfillmentReview::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'subject_id', 'audit_subject_id')->where('subject_type', self::class);
    }

    public function getAuditSubjectIdAttribute(): string
    {
        return (string) $this->getKey();
    }
}
