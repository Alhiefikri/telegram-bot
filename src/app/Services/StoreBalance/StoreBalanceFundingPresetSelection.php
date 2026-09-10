<?php

namespace App\Services\StoreBalance;

final readonly class StoreBalanceFundingPresetSelection
{
    public function __construct(
        public int $policyVersion,
        public int $index,
        public int $amount,
    ) {}
}
