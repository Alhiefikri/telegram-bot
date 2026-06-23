<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'cart_id', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'expires_at', 'telegram_receipt_sent_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['order_status' => OrderStatus::class, 'payment_status' => PaymentStatus::class, 'total_amount' => 'integer', 'expires_at' => 'datetime', 'telegram_receipt_sent_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
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
