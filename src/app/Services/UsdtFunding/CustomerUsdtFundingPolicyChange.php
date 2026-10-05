<?php

namespace App\Services\UsdtFunding;

final readonly class CustomerUsdtFundingPolicyChange
{
    public function __construct(
        public CustomerUsdtFundingPolicyState $oldState,
        public CustomerUsdtFundingPolicyState $newState,
        public bool $changed,
    ) {}
}
