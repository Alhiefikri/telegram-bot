<?php

namespace App\Services\UsdtBalance;

final readonly class UsdtBalanceReconciliationReport
{
    /**
     * @param  list<UsdtBalanceDriftFinding>  $findings
     */
    public function __construct(
        public int $accountsInspected,
        public array $findings,
        public ?int $nextCursor,
        public bool $hasMore,
    ) {}

    public function isConsistent(): bool
    {
        return $this->findings === [];
    }
}
