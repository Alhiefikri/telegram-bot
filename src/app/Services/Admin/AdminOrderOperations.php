<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderTransition;
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
        try {
            $completedOrder = $this->orders->completeManualOrder($order);
        } catch (InvalidOrderTransition $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->audit->log('order.manual_completed', $completedOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Completed->value, 'payment_status' => PaymentStatus::Paid->value], $user, $ip);

        return $completedOrder->fresh(['payments']);
    }

    public function rejectManualOrder(Order $order, ?User $user = null, ?string $ip = null): Order
    {
        try {
            $rejectedOrder = $this->orders->rejectManualOrder($order);
        } catch (InvalidOrderTransition $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $this->audit->log('order.manual_rejected', $rejectedOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Cancelled->value, 'payment_status' => PaymentStatus::Failed->value], $user, $ip);

        return $rejectedOrder->fresh(['payments', 'customer', 'orderItems']);
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

        SendTelegramReceipt::dispatch($order->getKey(), true, now()->toISOString())->onQueue('notifications');

        $this->audit->log('order.receipt_resent', $order, null, ['queue' => 'notifications'], $user, $ip);
    }
}
