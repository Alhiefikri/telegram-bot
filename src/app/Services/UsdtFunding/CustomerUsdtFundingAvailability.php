<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingAvailabilityReason;

final readonly class CustomerUsdtFundingAvailability
{
    private function __construct(
        public bool $available,
        public CustomerUsdtFundingAvailabilityReason $reason,
        public ?string $detail,
    ) {}

    public static function available(): self
    {
        return new self(true, CustomerUsdtFundingAvailabilityReason::Available, null);
    }

    public static function blocked(CustomerUsdtFundingAvailabilityReason $reason, ?string $detail = null): self
    {
        return new self(false, $reason, $detail);
    }
}
