<?php

namespace App\Support;

final class StockDisplay
{
    // Legacy unlimited products use the signed 32-bit ceiling and existing
    // reservations/sales decrement it. Keep presentation separate from math.
    public static function label(int $quantity): string
    {
        return $quantity >= 2_147_000_000 ? '∞' : (string) $quantity;
    }
}
