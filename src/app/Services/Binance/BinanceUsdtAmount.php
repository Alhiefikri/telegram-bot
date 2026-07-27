<?php

namespace App\Services\Binance;

class BinanceUsdtAmount
{
    /**
     * Convert a Binance decimal amount string (e.g. "0.502814", "-0.8", "2.5")
     * into micro-USDT integers without floating point drift. Returns null when
     * the value is not exactly representable in micro units.
     */
    public static function toMicro(string $amount): ?int
    {
        $amount = trim($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $matches) !== 1) {
            return null;
        }

        [, $sign, $whole, $fraction] = array_pad($matches, 4, '');

        if (strlen($fraction) > 6) {
            if (rtrim(substr($fraction, 6), '0') !== '') {
                return null;
            }

            $fraction = substr($fraction, 0, 6);
        }

        $micro = ((int) $whole) * 1_000_000 + (int) str_pad($fraction, 6, '0');

        return $sign === '-' ? -$micro : $micro;
    }
}
