<?php

namespace App\Services\UsdtFunding;

use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtTopUp;

final readonly class UsdtFundingIntentResult
{
    public function __construct(
        public string $outcome,
        public ?CustomerUsdtFundingIntent $intent = null,
        public ?CustomerUsdtTopUp $topUp = null,
    ) {}
}
