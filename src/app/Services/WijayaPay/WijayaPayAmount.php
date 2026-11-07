<?php

namespace App\Services\WijayaPay;

use InvalidArgumentException;

/**
 * Parses the integer rupiah values WijayaPay returns.
 *
 * The channel catalogue documents decimal strings such as "4700.00", while
 * transaction amounts are integers. Only an exactly representable whole
 * rupiah value is accepted; no rounding is ever applied to money.
 */
final readonly class WijayaPayAmount
{
    private function __construct(private int $value) {}

    public static function from(mixed $value, bool $allowZero = false): self
    {
        $maximumAmount = 1_000_000_000_000;
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed !== $value || preg_match('/^[+-]?\d+(?:\.0+)?$/', $trimmed) !== 1) {
                throw new InvalidArgumentException('WijayaPay amount is invalid.');
            }

            $negative = str_starts_with($trimmed, '-');
            $unsigned = ltrim($trimmed, '+-');
            $whole = strstr($unsigned, '.', true);
            $whole = $whole === false ? $unsigned : $whole;
            $whole = ltrim($whole, '0');
            $whole = $whole === '' ? '0' : $whole;

            $maximum = (string) PHP_INT_MAX;
            if (strlen($whole) > strlen($maximum)
                || (strlen($whole) === strlen($maximum) && strcmp($whole, $maximum) > 0)
            ) {
                throw new InvalidArgumentException('WijayaPay amount overflows.');
            }

            $amount = (int) $whole;
            if ($negative) {
                $amount = -$amount;
            }
        } else {
            throw new InvalidArgumentException('WijayaPay amount is invalid.');
        }

        if ($amount < 0 || (! $allowZero && $amount === 0) || $amount > $maximumAmount) {
            throw new InvalidArgumentException('WijayaPay amount is invalid.');
        }

        return new self($amount);
    }

    public static function nullable(mixed $value, bool $allowZero = false): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::from($value, allowZero: $allowZero);
    }

    public function value(): int
    {
        return $this->value;
    }
}
