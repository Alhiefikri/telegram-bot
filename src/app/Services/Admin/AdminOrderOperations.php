<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use InvalidArgumentException;

class AdminOrderOperations
{
    public function __construct(private readonly AuditLogger $audit) {}

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

        SendTelegramReceipt::dispatch($order->getKey())->onQueue('notifications');

        $this->audit->log('order.receipt_resent', $order, null, ['queue' => 'notifications'], $user, $ip);
    }
}
