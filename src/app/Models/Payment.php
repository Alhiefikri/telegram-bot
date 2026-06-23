<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'provider', 'merchant_ref', 'provider_reference', 'method', 'amount', 'currency', 'status', 'paid_at', 'expired_at'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'status' => PaymentStatus::class, 'paid_at' => 'datetime', 'expired_at' => 'datetime'];
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
