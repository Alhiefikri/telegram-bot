<?php

declare(strict_types=1);

namespace App\Services\Bsc;

use App\Models\Payment;

/**
 * Allocates a deterministic micro-USDT suffix while the payment setting row
 * is locked by the checkout transaction. Recent BSC payments of every
 * verification mode occupy their amount so late transfer-log scans cannot
 * attribute a reused amount to the wrong invoice.
 */
final class BscUniquePaymentAmount
{
    private const INCREMENT_MICRO = 100;

    private const CANDIDATE_BATCH_SIZE = 1_000;

    public function allocate(int $baseAmountMicro): int
    {
        $increment = max(1, (int) config('services.bsc.amount_unique_increment_micro', self::INCREMENT_MICRO));
        $lookbackHours = max(1, (int) config('services.bsc.late_payment_lookback_hours', 72));
        $minimumCreatedAt = now()->subHours($lookbackHours);
        if ($baseAmountMicro < 1) {
            return $baseAmountMicro;
        }

        for ($rangeStart = $baseAmountMicro; ; $rangeStart += self::CANDIDATE_BATCH_SIZE * $increment) {
            $rangeEnd = $rangeStart + ((self::CANDIDATE_BATCH_SIZE - 1) * $increment);
            $occupied = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'USDT_BEP20')
                ->where('created_at', '>=', $minimumCreatedAt)
                ->whereHas('bscDetail')
                ->whereBetween('amount', [$rangeStart, $rangeEnd])
                ->orderBy('amount')
                ->pluck('amount')
                ->mapWithKeys(fn (int|string $amount): array => [(int) $amount => true])
                ->all();

            for ($candidate = $rangeStart; $candidate <= $rangeEnd; $candidate += $increment) {
                if (! isset($occupied[$candidate])) {
                    return $candidate;
                }
            }
        }
    }
}
