<?php

namespace App\Enums;

enum ExternalMoneyReceiptStatus: string
{
    case Claimed = 'claimed';
    case Review = 'review';

    public function isReview(): bool
    {
        return $this === self::Review;
    }
}
