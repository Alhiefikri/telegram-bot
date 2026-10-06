<?php

namespace App\Enums;

/**
 * Coverage of Customer USDT liability plus unresolved deposits by qualified
 * custody assets and audited treasury movements. Only an exact zero
 * unexplained variance counts as consistent; unverifiable is its own verdict
 * and is never reported as zero backing.
 */
enum UsdtTreasuryCoverageVerdict: string
{
    case NotRequired = 'not_required';
    case Consistent = 'consistent';
    case Deficit = 'deficit';
    case Surplus = 'surplus';
    case Unverifiable = 'unverifiable';
    case NotEvaluated = 'not_evaluated';

    public function isFinding(): bool
    {
        return match ($this) {
            self::Deficit, self::Surplus, self::Unverifiable => true,
            self::NotRequired, self::Consistent, self::NotEvaluated => false,
        };
    }
}
