<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationState;

/**
 * Immutable snapshot of one Customer USDT funding reservation returned by the
 * reservation service. It carries the identity U6/U7 need (reservation id,
 * rail, amount, fee and the policy/terms versions confirmed against) without
 * exposing a mutable Eloquent model to downstream callers.
 */
final readonly class CustomerUsdtFundingReservationSnapshot
{
    public function __construct(
        public int $reservationId,
        public int $customerId,
        public CustomerUsdtFundingRail $rail,
        public int $amountMicro,
        public int $feeMicro,
        public int $policyVersion,
        public int $termsVersion,
        public CustomerUsdtFundingReservationState $state,
        public string $sourceType,
        public string $sourceId,
        public string $expiresAt,
        public ?string $resolvedAt,
        public ?int $settledEntryId,
    ) {}
}
