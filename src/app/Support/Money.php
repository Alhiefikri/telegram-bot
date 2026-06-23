<?php

namespace App\Support;

final class Money
{
    public static function idr(int|float|string|null $amount): string
    {
        return 'IDR '.number_format((int) $amount, 0, ',', '.');
    }
}
