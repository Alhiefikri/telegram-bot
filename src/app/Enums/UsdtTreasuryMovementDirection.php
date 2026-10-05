<?php

namespace App\Enums;

enum UsdtTreasuryMovementDirection: string
{
    case In = 'in';
    case Out = 'out';

    /**
     * Signed effect of the movement on the audited custody pool.
     */
    public function sign(): int
    {
        return $this === self::In ? 1 : -1;
    }
}
