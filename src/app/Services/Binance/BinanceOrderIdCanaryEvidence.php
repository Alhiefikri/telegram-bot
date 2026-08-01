<?php

namespace App\Services\Binance;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

final class BinanceOrderIdCanaryEvidence
{
    public function assertSuccessfulRecentAdminCanary(): Payment
    {
        $adminId = trim((string) config('services.telegram.admin_user_id'));
        $maxAgeHours = (int) config('services.binance.canary_max_age_hours', 24);

        if ($adminId === '' || $maxAgeHours < 1) {
            throw new RuntimeException('Binance admin canary verification is not configured safely.');
        }

        $receiverType = filled(config('services.binance.pay_id')) ? 'pay_id' : 'uid';
        $receiverValue = trim((string) (config('services.binance.pay_id') ?: config('services.binance.pay_uid')));
        if ($receiverValue === '') {
            throw new RuntimeException('Binance admin canary verification is not configured safely.');
        }

        $payment = Payment::query()
            ->with('order.customer')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('binance_verification_mode', 'order_id')
            ->where('status', PaymentStatus::Paid->value)
            ->where('binance_verification_status', 'confirmed')
            ->whereNotNull('binance_order_id')
            ->whereNotNull('binance_order_id_submitted_at')
            ->whereNotNull('provider_reference')
            ->where('binance_receiver_type', $receiverType)
            ->where('binance_receiver_value', $receiverValue)
            ->where('paid_at', '>=', now()->subHours($maxAgeHours))
            ->whereHas('order.customer', fn ($query) => $query->where('telegram_id', $adminId))
            ->latest('paid_at')
            ->first();

        if (! $payment
            || $payment->order?->order_status !== OrderStatus::Completed
            || $payment->order?->payment_status !== PaymentStatus::Paid
            || trim((string) $payment->binance_order_id) === ''
            || trim((string) $payment->provider_reference) === ''
            || ! in_array($payment->binance_receiver_type, ['pay_id', 'uid'], true)
            || trim((string) $payment->binance_receiver_value) === '') {
            throw new RuntimeException('No recent successful admin Binance Order ID canary evidence was found.');
        }

        return $payment;
    }
}
