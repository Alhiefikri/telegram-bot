<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'cart_id', 'checkout_key', 'terms_version', 'terms_accepted_at', 'notification_email', 'support_contact_channel', 'support_contact_value', 'invoice_number', 'order_status', 'payment_status', 'total_amount', 'currency', 'expires_at', 'telegram_expired_order_notification_started_at', 'telegram_expired_order_notified_at', 'telegram_receipt_sent_at', 'telegram_credential_message_chat_id', 'telegram_credential_message_id', 'telegram_credential_pinned_at', 'manual_checkout_customer_notified_at', 'manual_checkout_customer_notification_chat_id', 'manual_checkout_customer_notification_message_id', 'manual_checkout_customer_notification_deleted_at', 'manual_checkout_customer_notification_deletion_started_at', 'manual_checkout_customer_notification_deletion_token', 'buyer_order_detail_message_chat_id', 'buyer_order_detail_message_id', 'buyer_order_detail_message_deleted_at', 'buyer_order_detail_message_deletion_started_at', 'buyer_order_detail_message_deletion_token', 'manual_checkout_admin_notified_at', 'admin_pending_order_notified_at', 'admin_pending_order_notification_chat_id', 'admin_pending_order_notification_message_id', 'admin_pending_order_notification_deleted_at', 'admin_paid_order_notified_at', 'admin_expired_order_notified_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected $hidden = ['notification_email'];

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            if ($order->isDirty('balance_checkout_snapshot')) {
                throw new \LogicException('Balance checkout snapshots are immutable.');
            }
            if ($order->getRawOriginal('api_order_claim_id') !== null && $order->isDirty('api_order_claim_id')) {
                throw new \LogicException('Order API origin is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'order_status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'balance_checkout_snapshot' => 'array',
            'support_contact_value' => 'encrypted',
            'total_amount' => 'integer',
            'expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'notification_email' => 'encrypted',
            'telegram_expired_order_notification_started_at' => 'datetime',
            'telegram_expired_order_notified_at' => 'datetime',
            'telegram_receipt_sent_at' => 'datetime',
            'telegram_credential_message_id' => 'integer',
            'telegram_credential_pinned_at' => 'datetime',
            'manual_checkout_customer_notified_at' => 'datetime',
            'manual_checkout_customer_notification_message_id' => 'integer',
            'buyer_order_detail_message_id' => 'integer',
            'buyer_order_detail_message_deleted_at' => 'datetime',
            'buyer_order_detail_message_deletion_started_at' => 'datetime',
            'manual_checkout_customer_notification_deleted_at' => 'datetime',
            'manual_checkout_customer_notification_deletion_started_at' => 'datetime',
            'manual_checkout_admin_notified_at' => 'datetime',
            'admin_pending_order_notified_at' => 'datetime',
            'admin_pending_order_notification_message_id' => 'integer',
            'admin_pending_order_notification_deleted_at' => 'datetime',
            'admin_paid_order_notified_at' => 'datetime',
            'admin_expired_order_notified_at' => 'datetime',
        ];
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

    public function orderNotificationEvents()
    {
        return $this->hasMany(OrderNotificationEvent::class);
    }

    public function manualFulfillmentReviews()
    {
        return $this->hasMany(ManualFulfillmentReview::class);
    }

    public function hasOpenLatePaymentReview(): bool
    {
        return $this->manualFulfillmentReviews()->openLatePayment()->exists();
    }

    public function providerCancellationAttempts()
    {
        return $this->hasMany(ProviderCancellationAttempt::class);
    }

    public function salesProofPost()
    {
        return $this->hasOne(SalesProofPost::class);
    }

    public function telegramOutboundEffects()
    {
        return $this->hasMany(TelegramOutboundEffect::class, 'source_id')
            ->where('source_type', self::class);
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
