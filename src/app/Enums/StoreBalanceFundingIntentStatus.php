<?php

namespace App\Enums;

enum StoreBalanceFundingIntentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
