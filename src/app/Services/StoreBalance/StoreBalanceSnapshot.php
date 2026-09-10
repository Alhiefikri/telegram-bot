<?php

namespace App\Services\StoreBalance;

final class StoreBalanceSnapshot
{
    private function __construct(
        private readonly bool $available,
        private readonly int $balance,
    ) {}

    public static function available(int $balance): self
    {
        return new self(true, max(0, $balance));
    }

    /**
     * The projection cannot be trusted against the immutable ledger, so
     * callers must show a recovery state instead of an amount.
     */
    public static function unavailable(): self
    {
        return new self(false, 0);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function balance(): int
    {
        return $this->balance;
    }
}
