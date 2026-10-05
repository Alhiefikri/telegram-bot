<?php

namespace App\Services\UsdtBalance;

use Brick\Math\BigInteger;

final readonly class UsdtReceiptReconciliationReport
{
    /**
     * @param  list<UsdtReceiptFinding>  $findings
     */
    public function __construct(
        public int $receiptsInspected,
        public int $unreceiptedCreditsInspected,
        public array $findings,
        public ?int $nextReceiptCursor,
        public ?int $nextCreditCursor,
        public bool $receiptsHasMore,
        public bool $creditsHaveMore,
        public BigInteger $unresolvedDepositMicro,
        public BigInteger $overcreditedMicro,
    ) {}

    public function isConsistent(): bool
    {
        return $this->findings === [];
    }
}
