<?php

namespace App\Models;

use App\Enums\PaymentStatus;
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
    'amount',
    'currency',
    'status',
    'paid_at',
    'expired_at',
    'payment_proof_file_id', 'payment_proof_submitted_at', 'payment_proof_admin_chat_id',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'provider_payload' => 'array',
            'provider_fee' => 'integer',
            'provider_total_payment' => 'integer',
            'provider_created_at' => 'datetime',
            'provider_expires_at' => 'datetime',
            'provider_payment_number' => 'encrypted',
            'provider_create_attempted_at' => 'datetime',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'payment_proof_file_id' => 'encrypted',
            'payment_proof_submitted_at' => 'datetime',
        ];
    }

    protected function paymentMethodLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->providerName() === 'pakasir' => 'QRIS',
                default => $this->localPaymentMethodLabel(),
            },
        );
    }

    public function isNativePakasirQris(): bool
    {
        if ($this->providerName() !== 'pakasir' || $this->providerPaymentMethodName() !== 'qris') {
            return false;
        }

        return filled($this->provider_payment_number_hash)
            || $this->provider_created_at !== null
            || filled($this->provider_payment_number);
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

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'subject_id', 'audit_subject_id')->where('subject_type', self::class);
    }

    public function getAuditSubjectIdAttribute(): string
    {
        return (string) $this->getKey();
    }
}
