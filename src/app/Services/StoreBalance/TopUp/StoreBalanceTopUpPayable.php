<?php

namespace App\Services\StoreBalance\TopUp;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class StoreBalanceTopUpPayable
{
    public function __construct(
        private int $creditAmount,
        private ?int $providerFee,
        private int $totalAmount,
        private CarbonImmutable $expiresAt,
    ) {
        if ($creditAmount < 1 || $totalAmount < $creditAmount) {
            throw new InvalidArgumentException('Store Balance top-up payable amounts are invalid.');
        }

        if ($providerFee !== null && $providerFee < 0) {
            throw new InvalidArgumentException('Store Balance top-up provider fee is invalid.');
        }
    }

    public function creditAmount(): int
    {
        return $this->creditAmount;
    }

    public function providerFee(): ?int
    {
        return $this->providerFee;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function effectiveExpiresAt(): CarbonImmutable
    {
        return $this->expiresAt;
    }
}
