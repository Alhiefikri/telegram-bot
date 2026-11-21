<?php

namespace App\Services\TelegramStars;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Services\Checkout\TelegramStarsCheckoutIntent;

final class TelegramStarsInvoiceTarget
{
    public static function isPending(PaymentTelegramStarsDetail $target, Order $order, Payment $payment): bool
    {
        return $payment->isTelegramStars()
            && $payment->method === TelegramStarsCheckoutIntent::METHOD && $payment->currency === 'XTR'
            && $payment->status === PaymentStatus::Pending && $payment->manual_alternative_at === null
            && $payment->amount === $target->total_xtr && $payment->merchant_ref === $target->merchant_ref
            && $payment->order_id === $order->id && $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending && (int) $order->customer_id === $target->customer_id
            && $target->telegram_user_id > 0 && $target->telegram_user_id === $target->chat_id;
    }
}
