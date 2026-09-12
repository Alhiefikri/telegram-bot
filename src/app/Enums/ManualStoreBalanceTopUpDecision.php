<?php

namespace App\Enums;

enum ManualStoreBalanceTopUpDecision: string
{
    case Confirmed = 'confirmed';
    case AlreadyConfirmed = 'already_confirmed';
    case Rejected = 'rejected';
    case AlreadyRejected = 'already_rejected';
    case Expired = 'expired';
    case NotEligible = 'not_eligible';

    public function applied(): bool
    {
        return in_array($this, [self::Confirmed, self::Rejected], true);
    }
}
