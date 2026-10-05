<?php

namespace App\Enums;

enum ExternalMoneyReceiptClaimOutcome: string
{
    case Claimed = 'claimed';
    case Replayed = 'replayed';
    case Conflict = 'conflict';
    case Review = 'review';

    public function allowsMoneyEffect(): bool
    {
        return $this === self::Claimed || $this === self::Replayed;
    }

    public function isHeld(): bool
    {
        return ! $this->allowsMoneyEffect();
    }
}
