<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderStateMachine;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($order, $user, $ip): Order {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->load('payments');

            if (! $this->canCompleteManualOrder($lockedOrder)) {
                throw new InvalidArgumentException('Only pending manual orders can be completed.');
            }

            $payment = Payment::where('order_id', $lockedOrder->id)
                ->where('provider', 'manual')
                ->where('status', PaymentStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();

            $paidOrder = $this->orders->markPaymentPaid($payment);
            if ($paidOrder->order_status !== OrderStatus::Processing || $paidOrder->payment_status !== PaymentStatus::Paid) {
                throw new InvalidArgumentException('Only newly paid manual orders can be completed.');
            }

            $paidOrder->update(['order_status' => OrderStatus::Completed]);

            SendTelegramReceipt::dispatch($paidOrder->getKey())->onQueue('notifications')->afterCommit();

            $this->audit->log('order.manual_completed', $paidOrder, ['order_status' => OrderStatus::PendingPayment->value, 'payment_status' => PaymentStatus::Pending->value], ['order_status' => OrderStatus::Completed->value, 'payment_status' => PaymentStatus::Paid->value], $user, $ip);

            return $paidOrder->fresh(['payments']);
        }, 5);
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
