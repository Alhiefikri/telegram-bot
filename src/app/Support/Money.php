<?php

namespace App\Support;

use App\Services\UsdtBalance\UsdtMicroAmount;

final class Money
{
    public static function idr(int|float|string|null $amount): string
    {
        return 'IDR '.number_format((int) $amount, 0, ',', '.');
    }

    public static function rupiah(int|float|string|null $amount, string $thousandsSeparator = '.'): string
    {
        return 'Rp'.number_format((int) $amount, 0, $thousandsSeparator === '.' ? ',' : '.', $thousandsSeparator);
    }

    public static function usdt(int|float|string|null $microAmount): string
    {
        return UsdtMicroAmount::formatUnits(max(0, (int) $microAmount)).' USDT';
    }
}
