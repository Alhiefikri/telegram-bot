<?php

namespace App\Support;

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
        $amount = number_format(((int) $microAmount) / 1_000_000, 6, '.', '');
        $amount = rtrim(rtrim($amount, '0'), '.');

        return ($amount === '' ? '0' : $amount).' USDT';
    }
}
