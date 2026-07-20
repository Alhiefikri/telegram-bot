<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'cart_id', 'checkout_key', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'expires_at', 'telegram_receipt_sent_at', 'telegram_receipt_send_started_at', 'manual_checkout_customer_notified_at', 'manual_checkout_customer_notification_started_at', 'manual_checkout_customer_notification_chat_id', 'manual_checkout_customer_notification_message_id', 'manual_checkout_customer_notification_deleted_at', 'manual_checkout_customer_notification_deletion_started_at', 'manual_checkout_customer_notification_deletion_token', 'manual_checkout_admin_notified_at', 'manual_checkout_admin_notification_started_at', 'admin_pending_order_notification_started_at', 'admin_pending_order_notified_at', 'admin_pending_order_notification_chat_id', 'admin_pending_order_notification_message_id', 'admin_pending_order_notification_deleted_at', 'admin_paid_order_notification_started_at', 'admin_paid_order_notified_at', 'admin_expired_order_notification_started_at', 'admin_expired_order_notified_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['order_status' => OrderStatus::class, 'payment_status' => PaymentStatus::class, 'total_amount' => 'integer', 'expires_at' => 'datetime', 'telegram_receipt_sent_at' => 'datetime', 'telegram_receipt_send_started_at' => 'datetime', 'manual_checkout_customer_notified_at' => 'datetime', 'manual_checkout_customer_notification_started_at' => 'datetime', 'manual_checkout_customer_notification_message_id' => 'integer', 'manual_checkout_customer_notification_deleted_at' => 'datetime', 'manual_checkout_customer_notification_deletion_started_at' => 'datetime', 'manual_checkout_admin_notified_at' => 'datetime', 'manual_checkout_admin_notification_started_at' => 'datetime', 'admin_pending_order_notification_started_at' => 'datetime', 'admin_pending_order_notified_at' => 'datetime', 'admin_pending_order_notification_message_id' => 'integer', 'admin_pending_order_notification_deleted_at' => 'datetime', 'admin_paid_order_notification_started_at' => 'datetime', 'admin_paid_order_notified_at' => 'datetime', 'admin_expired_order_notification_started_at' => 'datetime', 'admin_expired_order_notified_at' => 'datetime'];
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

    public function salesProofPost()
    {
        return $this->hasOne(SalesProofPost::class);
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
