<?php

namespace App\Enums;

/**
 * Why a new Customer USDT funding target may or may not start right now.
 * Every state except Available blocks new targets and fail-closes the gate;
 * none of them blocks reconciliation or resolution of existing deposits.
 */
enum CustomerUsdtFundingAvailabilityReason: string
{
    case Available = 'available';
    case PolicyMissing = 'policy_missing';
    case FundingDisabled = 'funding_disabled';
    case FundingPaused = 'funding_paused';
    case RailNotRegistered = 'rail_not_registered';
    case RailNotReady = 'rail_not_ready';
    case RailPaused = 'rail_paused';
    case SpendingPaused = 'spending_paused';

    public function blocksNewFunding(): bool
    {
        return $this !== self::Available;
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Tersedia',
            self::PolicyMissing => 'Kebijakan belum tersedia',
            self::FundingDisabled => 'Belum diaktifkan Owner',
            self::FundingPaused => 'Top-up dijeda Owner',
            self::RailNotRegistered => 'Rail belum tersedia',
            self::RailNotReady => 'Rail belum siap menerima dana',
            self::RailPaused => 'Rail dijeda Owner',
            self::SpendingPaused => 'Belanja USDT dijeda Owner',
        };
    }
}
