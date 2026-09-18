<?php

namespace App\Services\Bsc;

use App\Exceptions\InvalidCheckout;

final class BscTokenAmount
{
    public function expectedTokenUnits(int $amountMicro, int $tokenDecimals): string
    {
        if ($amountMicro < 0) {
            throw new InvalidCheckout('BSC payment amount cannot be negative.');
        }
        if ($tokenDecimals < 6) {
            throw new InvalidCheckout('BSC token decimals cannot represent integer micro-USDT.');
        }
        if ($amountMicro === 0) {
            return '0';
        }

        return (string) $amountMicro.str_repeat('0', $tokenDecimals - 6);
    }
}
