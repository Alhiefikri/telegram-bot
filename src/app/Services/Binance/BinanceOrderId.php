<?php

namespace App\Services\Binance;

final class BinanceOrderId
{
    public const MAX_ATTEMPTS = 3;

    private function __construct() {}

    public static function normalize(string $value): ?string
    {
        $normalized = trim($value);

        if ($normalized === '' || strlen($normalized) < 10 || strlen($normalized) > 128 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{8,126}[A-Za-z0-9]\z/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }
}
