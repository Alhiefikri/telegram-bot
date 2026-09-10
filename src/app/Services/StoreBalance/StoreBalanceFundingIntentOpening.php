<?php

namespace App\Services\StoreBalance;

use App\Models\StoreBalanceFundingIntent;

final readonly class StoreBalanceFundingIntentOpening
{
    public function __construct(
        public ?StoreBalanceFundingIntent $intent,
        public ?string $rejection = null,
        public bool $reused = false,
    ) {}

    public function accepted(): bool
    {
        return $this->intent !== null;
    }
}
