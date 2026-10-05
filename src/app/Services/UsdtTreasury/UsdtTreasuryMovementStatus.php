<?php

namespace App\Services\UsdtTreasury;

enum UsdtTreasuryMovementStatus: string
{
    case Applied = 'applied';
    case Replayed = 'replayed';
}
