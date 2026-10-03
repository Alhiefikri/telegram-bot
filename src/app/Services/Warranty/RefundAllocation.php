<?php

namespace App\Services\Warranty;

use InvalidArgumentException;

final class RefundAllocation
{
    public static function unit(int $retailTotal, int $quantity, int $number): int
    {
        if ($retailTotal < 0 || $quantity < 1 || $number < 1 || $number > $quantity) {
            throw new InvalidArgumentException('Invalid refund entitlement.');
        }

        return intdiv($retailTotal, $quantity) + ($number <= $retailTotal % $quantity ? 1 : 0);
    }

    public static function remaining(int $originalAmount, int $alreadyRefunded): int
    {
        if ($originalAmount < 0 || $alreadyRefunded < 0 || $alreadyRefunded > $originalAmount) {
            throw new InvalidArgumentException('Refund exceeds original payment.');
        }

        return $originalAmount - $alreadyRefunded;
    }
}
