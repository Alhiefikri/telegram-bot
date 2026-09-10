<?php

namespace App\Services\StoreBalance;

final class StoreBalanceReconciliationReport
{
    /**
     * @param  list<StoreBalanceProjectionMismatch>  $mismatches
     */
    public function __construct(
        public readonly int $accountsInspected,
        public readonly array $mismatches,
    ) {}

    public function isConsistent(): bool
    {
        return $this->mismatches === [];
    }
}
