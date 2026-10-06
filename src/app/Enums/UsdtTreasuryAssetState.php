<?php

namespace App\Enums;

/**
 * Whether an approved treasury/custody adapter could produce an exact asset
 * observation. Unavailable or unsupported is never the same fact as a zero
 * balance: a zero balance is a proven amount, while these states mean the
 * backing cannot be proven at all.
 */
enum UsdtTreasuryAssetState: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Unsupported = 'unsupported';

    public function provesAssets(): bool
    {
        return $this === self::Available;
    }
}
