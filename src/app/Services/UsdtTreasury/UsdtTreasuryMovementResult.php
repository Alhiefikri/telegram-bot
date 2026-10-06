<?php

namespace App\Services\UsdtTreasury;

final readonly class UsdtTreasuryMovementResult
{
    public function __construct(
        public UsdtTreasuryMovementStatus $status,
        public int $movementId,
    ) {}
}
