<?php

namespace App\Services\Binance;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

/**
 * Compatibility allocator for the pre-activation amount_unique mode.
 * Order ID payments never use this path and always keep the catalog amount.
 */
final class BinanceUniquePaymentAmount
{
    private const MICRO_PER_MILLI_USDT = 1_000;

    public function __construct(private readonly BinancePayClient $client) {}

    public function allocate(int $baseAmountMicro): int
    {
        if (! $this->client->configured()) {
            return $baseAmountMicro;
        }

        [$minOffset, $maxOffset] = $this->offsetRange();
        $base = (int) (ceil($baseAmountMicro / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT);
        $minMilli = intdiv($minOffset, self::MICRO_PER_MILLI_USDT);
        $maxMilli = intdiv($maxOffset, self::MICRO_PER_MILLI_USDT);
        $candidateCount = $maxMilli - $minMilli + 1;
        $maxAttempts = min(2_048, max(64, $candidateCount * 2));

        // Sample without materializing an arbitrarily large configured range.
        // The partial unique index remains the final race-safe guard at
        // insert time; this loop only avoids predictable collisions.
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $offset = random_int($minMilli, $maxMilli) * self::MICRO_PER_MILLI_USDT;
            $candidate = $base + $offset;
            $taken = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending->value)
                ->where('binance_auto_verification_enabled', true)
                ->where('binance_verification_mode', 'amount_unique')
                ->where('amount', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique Binance payment amount.');
    }

    /** @return array{0: int, 1: int} */
    private function offsetRange(): array
    {
        $min = (int) config('services.binance.unique_amount_min_offset_micro', 1_000);
        $max = (int) config('services.binance.unique_amount_max_offset_micro', 999_000);
        if ($min === 101 && $max === 9_999) {
            $min = 1_000;
            $max = 999_000;
        }
        $min = max(self::MICRO_PER_MILLI_USDT, (int) ceil($min / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT);
        $max = max($min, (int) floor($max / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT);

        return [$min, $max];
    }
}
