<?php

namespace App\Services\Binance;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

class BinanceUniquePaymentAmount
{
    private const MAX_ATTEMPTS = 25;

    public function __construct(private readonly BinancePayClient $client) {}

    /**
     * Add a small random micro-USDT offset so every active Binance order has a
     * distinct payable amount. The offset alone identifies the order during
     * automatic verification, so two pending payments must never share it.
     * Without API credentials there is no automatic verification, so the base
     * amount is kept as-is for the manual screenshot flow.
     */
    public function allocate(int $baseAmountMicro): int
    {
        if (! $this->client->configured()) {
            return $baseAmountMicro;
        }

        [$minOffset, $maxOffset] = $this->offsetRange();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $baseAmountMicro + random_int($minOffset, $maxOffset);

            $taken = Payment::query()
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending->value)
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
        $min = (int) config('services.binance.unique_amount_min_offset_micro', 101);
        $max = (int) config('services.binance.unique_amount_max_offset_micro', 9999);

        $min = max(1, $min);
        $max = max($min, $max);

        return [$min, $max];
    }
}
