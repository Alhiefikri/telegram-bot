<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceFundingPolicyChange
{
    public function __construct(
        public StoreBalanceFundingPolicyState $oldState,
        public StoreBalanceFundingPolicyState $newState,
        public bool $changed,
    ) {}
}
