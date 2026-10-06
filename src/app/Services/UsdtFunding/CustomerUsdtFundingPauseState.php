<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingPauseScope;

final readonly class CustomerUsdtFundingPauseState
{
    public function __construct(
        public CustomerUsdtFundingPauseScope $scope,
        public string $reason,
        public ?int $pausedByUserId,
        public string $pausedAt,
    ) {}

    /** @return array{scope: string, reason: string, paused_by_user_id: int|null, paused_at: string} */
    public function auditValues(): array
    {
        return [
            'scope' => $this->scope->value,
            'reason' => $this->reason,
            'paused_by_user_id' => $this->pausedByUserId,
            'paused_at' => $this->pausedAt,
        ];
    }
}
