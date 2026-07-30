<?php

namespace App\Services\Binance;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use RuntimeException;

class BinanceUniquePaymentAmount
{
    private const MICRO_PER_MILLI_USDT = 1_000;

    public function __construct(private readonly BinancePayClient $client) {}

    public function enabled(): bool
    {
        return $this->client->configured();
    }

    /**
     * Add a randomized mill-USDT offset so every active Binance order has a
     * distinct, easy-to-type payable amount. The offset identifies the order
     * during automatic verification, so pending payments never share it.
     * Without API credentials there is no automatic verification, so the base
     * amount is kept as-is for the manual screenshot flow.
     */
    public function allocate(int $baseAmountMicro): int
    {
        if (! $this->enabled()) {
            return $baseAmountMicro;
        }

        [$minOffset, $maxOffset] = $this->offsetRange();
        $base = (int) (ceil($baseAmountMicro / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT);
        $offsets = range($minOffset, $maxOffset, self::MICRO_PER_MILLI_USDT);
        shuffle($offsets);

        foreach ($offsets as $offset) {
            $candidate = $base + $offset;

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
        $min = (int) config('services.binance.unique_amount_min_offset_micro', 1_000);
        $max = (int) config('services.binance.unique_amount_max_offset_micro', 999_000);

        if ($min === 101 && $max === 9_999) {
            $min = 1_000;
            $max = 999_000;
        }

        $min = max(self::MICRO_PER_MILLI_USDT, (int) ceil($min / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT);
        $max = (int) floor($max / self::MICRO_PER_MILLI_USDT) * self::MICRO_PER_MILLI_USDT;
        $max = max($min, $max);

        return [$min, $max];
    }
}
