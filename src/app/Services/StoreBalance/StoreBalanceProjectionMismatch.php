<?php

namespace App\Services\StoreBalance;

final class StoreBalanceProjectionMismatch
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $projectedBalance,
        public readonly int $ledgerBalance,
    ) {}
}
