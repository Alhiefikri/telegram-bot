<?php

namespace App\Enums;

enum ExternalMoneyReceiptClaimOutcome: string
{
    case Claimed = 'claimed';
    case Replayed = 'replayed';
    case Conflict = 'conflict';
    case Review = 'review';
}
