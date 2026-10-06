<?php

namespace App\Enums;

/**
 * Outcome of one reserve() call. `Created` claims the Customer's open target
 * slot; `Replayed` returns the same reservation for a repeated source;
 * `ExistingActive` returns the Customer's current open target instead of
 * opening a second one across rails.
 */
enum CustomerUsdtFundingReservationOutcome: string
{
    case Created = 'created';
    case Replayed = 'replayed';
    case ExistingActive = 'existing_active';
    case Settled = 'settled';
    case Released = 'released';
}
