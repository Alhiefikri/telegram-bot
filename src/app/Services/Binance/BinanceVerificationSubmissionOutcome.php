<?php

namespace App\Services\Binance;

enum BinanceVerificationSubmissionOutcome: string
{
    case Accepted = 'accepted';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case GraceExpired = 'grace_expired';
    case Manual = 'manual';
    case Limit = 'limit';
}
