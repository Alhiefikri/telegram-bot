<?php

namespace App\Services\KlikQris;

use UnexpectedValueException;

final class KlikQrisReportingInteger
{
    public static function positive(mixed $value): int
    {
        $integer = self::nonNegative($value);
        if ($integer < 1) {
            throw new UnexpectedValueException('KlikQRIS paginator integer must be positive.');
        }

        return $integer;
    }

    public static function nonNegative(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new UnexpectedValueException('KlikQRIS paginator integer is invalid.');
            }

            return $value;
        }

        if (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new UnexpectedValueException('KlikQRIS paginator integer is invalid.');
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw new UnexpectedValueException('KlikQRIS paginator integer is out of range.');
        }

        return (int) $value;
    }
}
