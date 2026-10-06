<?php

namespace App\Services\UsdtBalance;

enum UsdtBalanceFreezeOutcome: string
{
    case Frozen = 'frozen';
    case AlreadyFrozen = 'already_frozen';
    case Stale = 'stale';
    case Missing = 'missing';
    case Failed = 'failed';
}
