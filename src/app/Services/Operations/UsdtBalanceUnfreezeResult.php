<?php

namespace App\Services\Operations;

use App\Enums\UsdtBalanceUnfreezeStatus;

/** Sanitized result of one deliberate USDT account unfreeze attempt (#558). */
final readonly class UsdtBalanceUnfreezeResult
{
    /** @param  list<string>  $issues */
    public function __construct(
        public UsdtBalanceUnfreezeStatus $status,
        public int $customerId,
        public ?int $accountId,
        public ?string $frozenReason,
        public array $issues,
        public string $actor,
        public string $reason,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'customer_id' => $this->customerId,
            'account_id' => $this->accountId,
            'frozen_reason' => $this->frozenReason,
            'issues' => $this->issues,
            'actor' => $this->actor,
            'reason' => $this->reason,
        ];
    }
}
