<?php

namespace App\Services\UsdtTreasury;

use Brick\Math\BigInteger;
use RuntimeException;

/**
 * Audited custody movement totals used by coverage reconciliation. In/out
 * totals keep their exact direction: a movement is never netted into customer
 * liability or a customer balance.
 */
final readonly class UsdtTreasuryMovementTotals
{
    public function __construct(
        public int $movementCount,
        public BigInteger $inMicro,
        public BigInteger $outMicro,
    ) {}

    public static function fromDatabase(int $movementCount, int|string|null $inMicro, int|string|null $outMicro): self
    {
        return new self(
            $movementCount,
            self::exact($inMicro),
            self::exact($outMicro),
        );
    }

    public function netMicro(): BigInteger
    {
        return $this->inMicro->minus($this->outMicro);
    }

    private static function exact(int|string|float|null $value): BigInteger
    {
        if ($value === null || $value === '') {
            return BigInteger::zero();
        }

        if (is_float($value)) {
            throw new RuntimeException('USDT treasury movement totals exceed the supported exact integer range.');
        }

        return BigInteger::of((string) $value);
    }
}
