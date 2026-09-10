<?php

namespace App\Enums;

enum StoreBalanceFundingIntentClaimOutcome: string
{
    case Confirmed = 'confirmed';
    case AlreadyConfirmed = 'already_confirmed';
    case Cancelled = 'cancelled';
    case AlreadyCancelled = 'already_cancelled';
    case Expired = 'expired';
    case Stale = 'stale';
}
