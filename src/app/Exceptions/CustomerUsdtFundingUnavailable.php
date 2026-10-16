<?php

namespace App\Exceptions;

use App\Enums\CustomerUsdtFundingAvailabilityReason;
use RuntimeException;

/**
 * A new Customer USDT funding target was requested while the versioned policy
 * or rail readiness closed the gate. Existing reservations, settlements,
 * refunds and reconciliation are never blocked by this exception.
 */
final class CustomerUsdtFundingUnavailable extends RuntimeException
{
    public function __construct(
        public readonly CustomerUsdtFundingAvailabilityReason $reason,
        public readonly ?string $detail = null,
    ) {
        $message = match ($reason) {
            CustomerUsdtFundingAvailabilityReason::PolicyMissing => 'Kebijakan top-up USDT belum tersedia.',
            CustomerUsdtFundingAvailabilityReason::FundingDisabled => 'Top-up USDT belum diaktifkan Owner.',
            CustomerUsdtFundingAvailabilityReason::FundingPaused => 'Top-up USDT sedang dijeda Owner.',
            CustomerUsdtFundingAvailabilityReason::RailNotRegistered => 'Rail top-up USDT belum tersedia.',
            CustomerUsdtFundingAvailabilityReason::RailNotReady => 'Rail top-up USDT belum siap menerima dana.',
            CustomerUsdtFundingAvailabilityReason::RailPaused => 'Rail top-up USDT sedang dijeda Owner.',
            CustomerUsdtFundingAvailabilityReason::SpendingPaused => 'Belanja saldo sedang dijeda Owner.',
            CustomerUsdtFundingAvailabilityReason::RestoreGateOpen => 'Pemulihan basis data saldo belum diverifikasi; top-up dan belanja saldo ditahan.',
            CustomerUsdtFundingAvailabilityReason::Available => 'Top-up USDT tersedia.',
        };

        parent::__construct($detail === null ? $message : $message.' ('.$detail.')');
    }
}
