<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;

class OrderLifecycleProjection
{
    public function __construct(
        public readonly OrderStatus $orderStatus,
        public readonly ?PaymentStatus $paymentStatus,
        public readonly bool $isPaid,
        public readonly bool $isPendingPayment,
        public readonly bool $isTerminal,
        public readonly string $terminalCause,
    ) {}

    public static function from(Order $order, ?Payment $payment): self
    {
        $isPaid = $payment?->status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);

        $isPendingPayment = $payment?->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment;

        $isTerminal = $payment?->status === PaymentStatus::Expired
            || $payment?->status === PaymentStatus::Failed
            || in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);

        return new self(
            orderStatus: $order->order_status,
            paymentStatus: $payment?->status ?? $order->payment_status,
            isPaid: $isPaid,
            isPendingPayment: $isPendingPayment,
            isTerminal: $isTerminal,
            terminalCause: self::terminalCause($order, $payment),
        );
    }

    private static function terminalCause(Order $order, ?Payment $payment): string
    {
        return match (true) {
            $payment?->status === PaymentStatus::Expired || $order->order_status === OrderStatus::Expired => 'expired',
            $order->order_status === OrderStatus::Cancelled => 'cancelled',
            $payment?->status === PaymentStatus::Failed => 'provider_failed',
            default => 'terminal',
        };
    }
}
