<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceFundingIntentClaimOutcome;
use App\Models\StoreBalanceFundingIntent;

final readonly class StoreBalanceFundingIntentClaim
{
    public function __construct(
        public StoreBalanceFundingIntentClaimOutcome $outcome,
        public ?StoreBalanceFundingIntent $intent = null,
    ) {}
}
