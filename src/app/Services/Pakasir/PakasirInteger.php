<?php

namespace App\Services\Pakasir;

final class PakasirInteger
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public static function parsePositive(mixed $value): ?int
    {
        $integer = self::parse($value);

        return $integer !== null && $integer > 0 ? $integer : null;
    }
}
