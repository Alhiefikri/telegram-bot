<?php

declare(strict_types=1);

namespace App\Services\Bsc;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the catalog amount when free, then tries 0.001-USDT steps.
 * Checkout holds the payment-setting row lock across allocation and insert.
 * Pending checkout Payments and recent shared top-up targets reserve an amount.
 */
final class BscUniquePaymentAmount
{
    private const INCREMENT_MICRO = 1_000;

    private const CANDIDATE_BATCH_SIZE = 1_000;

    public function allocate(int $baseAmountMicro): int
    {
        $increment = self::INCREMENT_MICRO;
        if ($baseAmountMicro < 1) {
            return $baseAmountMicro;
        }

        for ($rangeStart = $baseAmountMicro; ; $rangeStart += self::CANDIDATE_BATCH_SIZE * $increment) {
            $rangeEnd = $rangeStart + ((self::CANDIDATE_BATCH_SIZE - 1) * $increment);
            $occupied = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'USDT_BEP20')
                ->where('status', PaymentStatus::Pending->value)
                ->whereHas('order', fn ($order) => $order->where('order_status', OrderStatus::PendingPayment->value))
                ->whereHas('bscDetail')
                ->whereBetween('amount', [$rangeStart, $rangeEnd])
                ->orderBy('amount')
                ->pluck('amount')
                ->mapWithKeys(fn (int|string $amount): array => [(int) $amount => true])
                ->all();

            $intents = DB::table('customer_usdt_funding_intents')
                ->where('rail', 'bsc_shared')->where('state', 'pending')->where('expires_at', '>', now())
                ->whereBetween('payable_micro', [$rangeStart, $rangeEnd])->pluck('payable_micro');
            $topUps = DB::table('customer_usdt_top_ups')
                ->where('rail', 'bsc_shared')->where('created_at', '>=', now()->subHours(24))
                ->whereBetween('payable_micro', [$rangeStart, $rangeEnd])->pluck('payable_micro');
            foreach ($intents->merge($topUps) as $amount) {
                $occupied[(int) $amount] = true;
            }

            for ($candidate = $rangeStart; $candidate <= $rangeEnd; $candidate += $increment) {
                if (! isset($occupied[$candidate])) {
                    return $candidate;
                }
            }
        }
    }
}
