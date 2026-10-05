<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingReservationOutcome;

final readonly class CustomerUsdtFundingReservationResult
{
    public function __construct(
        public CustomerUsdtFundingReservationOutcome $outcome,
        public CustomerUsdtFundingReservationSnapshot $reservation,
    ) {}

    public function wasCreated(): bool
    {
        return $this->outcome === CustomerUsdtFundingReservationOutcome::Created;
    }
}
