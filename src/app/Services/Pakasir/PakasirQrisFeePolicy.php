<?php

namespace App\Services\Pakasir;

use UnexpectedValueException;

final readonly class PakasirQrisFeePolicy
{
    public function __construct(
        private string $payer,
        private int $threshold,
        private int $lowBps,
        private int $lowFixed,
        private int $highBps,
        private int $highFixed,
    ) {
        if (! in_array($this->payer, ['customer', 'merchant'], true)) {
            throw new UnexpectedValueException('PAKASIR_QRIS_FEE_PAYER must be customer or merchant.');
        }
        if ($this->threshold <= 0) {
            throw new UnexpectedValueException('PAKASIR_QRIS_FEE_THRESHOLD must be a positive integer.');
        }
        foreach ([$this->lowBps, $this->lowFixed, $this->highBps, $this->highFixed] as $value) {
            if ($value < 0) {
                throw new UnexpectedValueException('Pakasir QRIS fee configuration values must be non-negative integers.');
            }
        }
        if ($this->lowBps > 10_000 || $this->highBps > 10_000 || $this->lowFixed > 10_000 || $this->highFixed > 10_000) {
            throw new UnexpectedValueException('Pakasir QRIS fee configuration exceeds production safety limits.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.pakasir.qris_fee.payer', 'customer'),
            (int) config('services.pakasir.qris_fee.threshold', 105_000),
            (int) config('services.pakasir.qris_fee.low_bps', 70),
            (int) config('services.pakasir.qris_fee.low_fixed', 310),
            (int) config('services.pakasir.qris_fee.high_bps', 100),
            (int) config('services.pakasir.qris_fee.high_fixed', 0),
        );
    }

    public static function officialCustomer(): self
    {
        return new self('customer', 105_000, 70, 310, 100, 0);
    }

    /** @return list<int> */
    public function allowedFees(int $amount): array
    {
        if ($amount <= 0) {
            throw new UnexpectedValueException('Pakasir QRIS amount must be positive.');
        }
        $bps = $amount <= $this->threshold ? $this->lowBps : $this->highBps;
        $fixed = $amount <= $this->threshold ? $this->lowFixed : $this->highFixed;
        $numerator = $amount * $bps;
        $fees = array_values(array_unique([
            intdiv($numerator, 10_000) + $fixed,
            intdiv($numerator + 9_999, 10_000) + $fixed,
        ]));
        sort($fees);

        return $fees;
    }

    public function assertValid(int $amount, int $fee, int $total): void
    {
        if (! in_array($fee, $this->allowedFees($amount), true)) {
            throw new UnexpectedValueException('Pakasir QRIS fee mismatch.');
        }
        if ($total !== $this->buyerTotal($amount, $fee)) {
            throw new UnexpectedValueException('Pakasir QRIS total mismatch.');
        }
    }

    public function buyerTotal(int $amount, int $fee): int
    {
        return $this->payer === 'customer' ? $amount + $fee : $amount;
    }

    public function feePayer(): string
    {
        return $this->payer;
    }
}
