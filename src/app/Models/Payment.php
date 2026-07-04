<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    'method',
    'amount',
    'currency',
    'status',
    'paid_at',
    'expired_at',
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
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
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
