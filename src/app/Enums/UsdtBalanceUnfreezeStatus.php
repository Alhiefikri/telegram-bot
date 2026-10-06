<?php

namespace App\Enums;

/**
 * Outcome of one deliberate operator unfreeze of a frozen Customer USDT
 * Balance account (#558). Only a drift freeze whose drift is gone may be
 * cleared; a reorg incident keeps its freeze until the loss is resolved by the
 * authorized recovery process.
 */
enum UsdtBalanceUnfreezeStatus: string
{
    case Unfrozen = 'unfrozen';
    case DryRunEligible = 'dry_run_eligible';
    case NotFrozen = 'not_frozen';
    case MissingAccount = 'missing_account';
    case DriftRemains = 'drift_remains';
    case ReorgHold = 'reorg_hold';

    public function isSuccess(): bool
    {
        return $this === self::Unfrozen || $this === self::DryRunEligible;
    }
}
