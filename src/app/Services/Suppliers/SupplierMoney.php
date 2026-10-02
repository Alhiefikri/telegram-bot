<?php

namespace App\Services\Suppliers;

use InvalidArgumentException;

final class SupplierMoney
{
    public const SCALE = 1_000_000;

    public static function micros(int|float|string $value): int
    {
        // The HTTP decoder preserves numeric decimal tokens as strings. Floats are
        // accepted only at the public boundary, never used for economic arithmetic.
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Invalid supplier amount.');
            }
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        $value = (string) $value;
        if (strlen($value) > 32 || ! preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?\z/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid supplier amount.');
        }
        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 6, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('Supplier amount overflow.');
        }

        return (int) $digits;
    }

    public static function decimal(int $micros): string
    {
        if ($micros < 0) {
            throw new InvalidArgumentException('Invalid supplier amount.');
        }

        return intdiv($micros, self::SCALE).'.'.str_pad((string) ($micros % self::SCALE), 6, '0', STR_PAD_LEFT);
    }

    public static function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new InvalidArgumentException('Supplier amount overflow.');
        }

        return $left + $right;
    }

    public static function checkedMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right))) {
            throw new InvalidArgumentException('Supplier amount overflow.');
        }

        return $left * $right;
    }

    public static function costIdr(int $micros, int $rate): int
    {
        if ($micros < 0 || $rate <= 0) {
            throw new InvalidArgumentException('Invalid supplier conversion.');
        }
        $whole = self::checkedMultiply(intdiv($micros, self::SCALE), $rate);
        // Split the rate too: a representable final amount need not have a
        // representable intermediate micros * rate product.
        $remainder = $micros % self::SCALE;
        $fractionWhole = self::checkedMultiply($remainder, intdiv($rate, self::SCALE));
        $fraction = $remainder * ($rate % self::SCALE);
        $rounded = intdiv($fraction, self::SCALE) + ($fraction % self::SCALE > 0 ? 1 : 0);

        return self::checkedAdd($whole, self::checkedAdd($fractionWhole, $rounded));
    }
}
