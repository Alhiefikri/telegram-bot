<?php

namespace App\Services\StoreBalance;

use App\Models\StoreBalanceCompensation;
use App\Models\StoreBalanceTopUp;

final class StoreBalanceOwnerCompensationResult
{
    public function __construct(
        public readonly StoreBalanceCompensation $compensation,
        public readonly StoreBalanceTopUp $topUp,
        public readonly int $balanceBefore,
        public readonly int $balanceAfter,
        public readonly bool $applied,
    ) {}
}
