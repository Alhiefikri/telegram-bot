<?php

namespace App\Services\UsdtBalance;

final class UsdtBalanceSnapshot
{
    private function __construct(
        private readonly bool $available,
        private readonly int $balanceMicro,
        private readonly ?string $frozenReason,
    ) {}

    public static function available(int $balanceMicro, ?string $frozenReason = null): self
    {
        return new self(true, max(0, $balanceMicro), $frozenReason);
    }

    /**
     * The projection cannot be trusted against its immutable cursor, so
     * callers must show a recovery state instead of an amount.
     */
    public static function unavailable(?string $frozenReason = null): self
    {
        return new self(false, 0, $frozenReason);
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function balanceMicro(): int
    {
        return $this->balanceMicro;
    }

    public function currency(): string
    {
        return UsdtMicroAmount::CURRENCY;
    }

    public function isFrozen(): bool
    {
        return $this->frozenReason !== null;
    }

    public function frozenReason(): ?string
    {
        return $this->frozenReason;
    }
}
