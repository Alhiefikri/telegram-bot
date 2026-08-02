<?php

namespace App\Services\Binance;

use App\Enums\PaymentStatus;
use App\Models\Payment;

/**
 * Compatibility allocator for the pre-activation amount_unique mode.
 * Order ID payments never use this path and always keep the catalog amount.
 */
final class BinanceUniquePaymentAmount
{
    private const MICRO_PER_MILLI_USDT = 1_000;

    private const CANDIDATE_BATCH_SIZE = 1_000;
    public function __construct(private readonly BinancePayClient $client) {}

    public function allocate(int $baseAmountMicro): int
    {
        if (! $this->client->configured()) {
            return $baseAmountMicro;
        }

        for ($rangeStart = $baseAmountMicro; ; $rangeStart += self::CANDIDATE_BATCH_SIZE * self::MICRO_PER_MILLI_USDT) {
            $rangeEnd = $rangeStart + ((self::CANDIDATE_BATCH_SIZE - 1) * self::MICRO_PER_MILLI_USDT);
            $occupied = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending->value)
                ->where('binance_auto_verification_enabled', true)
                ->where('binance_verification_mode', 'amount_unique')
                ->whereBetween('amount', [$rangeStart, $rangeEnd])
                ->orderBy('amount')
                ->pluck('amount')
                ->mapWithKeys(fn (int $amount): array => [$amount => true])
                ->all();

            for ($candidate = $rangeStart; $candidate <= $rangeEnd; $candidate += self::MICRO_PER_MILLI_USDT) {
                if (! isset($occupied[$candidate])) {
                    return $candidate;
                }
            }
        }
    }
}
