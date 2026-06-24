<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderStateMachine;
use InvalidArgumentException;

class AdminOrderOperations
{
    public function __construct(private readonly AuditLogger $audit, private readonly OrderStateMachine $orders) {}

    public function canCompleteManualOrder(Order $order): bool
    {
        $order->loadMissing('payments');
        $payment = $order->payments->first();

        return $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending
            && $payment?->provider === 'manual'
            && $payment->status === PaymentStatus::Pending;
    }

    public function completeManualOrder(Order $order, ?User $user = null, ?string $ip = null): Order
    {
        if (! $this->canCompleteManualOrder($order)) {
            throw new InvalidArgumentException('Only pending manual orders can be completed.');
        }

        $payment = $order->payments()->where('provider', 'manual')->where('status', PaymentStatus::Pending)->oldest()->firstOrFail();
        $paidOrder = $this->orders->markPaymentPaid($payment);
        $paidOrder->update(['order_status' => OrderStatus::Completed]);

        SendTelegramReceipt::dispatch($paidOrder->getKey())->onQueue('notifications');

        $this->audit->log('order.manual_completed', $paidOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Completed->value, 'payment_status' => PaymentStatus::Paid->value], $user, $ip);

        return $paidOrder->fresh(['payments']);
    }

    public function canResendReceipt(Order $order): bool
    {
        $order->loadMissing('customer');

        return $order->customer?->telegram_id !== null
            && $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
    }

    public function resendReceipt(Order $order, ?User $user = null, ?string $ip = null): void
    {
        if (! $this->canResendReceipt($order)) {
            throw new InvalidArgumentException('Receipt can only be resent for paid orders with a Telegram customer.');
        }

        SendTelegramReceipt::dispatch($order->getKey(), force: true)->onQueue('notifications');

        $this->audit->log('order.receipt_resent', $order, null, ['queue' => 'notifications'], $user, $ip);
    }
}
