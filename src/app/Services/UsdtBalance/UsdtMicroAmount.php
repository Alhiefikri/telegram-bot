<?php

namespace App\Services\UsdtBalance;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use InvalidArgumentException;

/**
 * Exact positive USDT money in integer micro-USDT (10^6 per USDT). Input is a
 * strict unsigned decimal string; raw on-chain token units stay BigInteger
 * strings until they are proven exactly representable, and every conversion
 * rejects a non-zero remainder or integer overflow instead of rounding.
 */
final class UsdtMicroAmount
{
    public const SCALE = 6;

    public const CURRENCY = 'USDT';

    private const MAX_INPUT_LENGTH = 32;

    private const MAX_RAW_UNITS_LENGTH = 78;

    private const MAX_TOKEN_DECIMALS = 36;

    private function __construct(public readonly int $units) {}

    public static function fromDecimalString(string $amount): self
    {
        if ($amount === '' || strlen($amount) > self::MAX_INPUT_LENGTH
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $amount) !== 1) {
            throw new InvalidArgumentException('USDT amount must be an unsigned decimal string without exponent or ambiguous separators.');
        }

        try {
            $units = BigDecimal::of($amount)->toScale(self::SCALE)->getUnscaledValue()->toInt();
        } catch (RoundingNecessaryException|IntegerOverflowException|NumberFormatException) {
            throw new InvalidArgumentException('USDT amount is not exactly representable in micro-USDT.');
        }

        return self::fromUnits($units);
    }

    public static function fromUnits(int $units): self
    {
        if ($units <= 0) {
            throw new InvalidArgumentException('USDT amounts must be positive integer micro-USDT.');
        }

        return new self($units);
    }

    /**
     * Exact, zero-safe display form in canonical USDT units with trailing
     * zeros removed (e.g. 1500000 -> "1.5", 0 -> "0"). Formatting never
     * divides by a float.
     */
    public static function formatUnits(int $units): string
    {
        if ($units < 0) {
            throw new InvalidArgumentException('USDT display amounts cannot be negative.');
        }

        $decimal = BigDecimal::ofUnscaledValue($units, self::SCALE)->toString();
        $trimmed = rtrim(rtrim($decimal, '0'), '.');

        return $trimmed === '' || $trimmed === '0' ? '0' : $trimmed;
    }

    public static function fromRawTokenUnits(string $rawUnits, int $tokenDecimals): self
    {
        if ($tokenDecimals < 0 || $tokenDecimals > self::MAX_TOKEN_DECIMALS) {
            throw new InvalidArgumentException('USDT token decimals are outside the supported range.');
        }
        if ($rawUnits === '' || strlen($rawUnits) > self::MAX_RAW_UNITS_LENGTH
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $rawUnits) !== 1) {
            throw new InvalidArgumentException('Raw token units must be a canonical unsigned integer string.');
        }

        try {
            $raw = BigInteger::of($rawUnits);
            $units = $tokenDecimals >= self::SCALE
                ? $raw->dividedBy(BigInteger::ten()->power($tokenDecimals - self::SCALE))->toInt()
                : $raw->multipliedBy(BigInteger::ten()->power(self::SCALE - $tokenDecimals))->toInt();
        } catch (RoundingNecessaryException|IntegerOverflowException|NumberFormatException) {
            throw new InvalidArgumentException('Raw token units are not exactly representable in micro-USDT.');
        }

        return self::fromUnits($units);
    }

    public function units(): int
    {
        return $this->units;
    }

    public function integerUnits(): string
    {
        return (string) $this->units;
    }

    public function decimal(): string
    {
        return BigDecimal::ofUnscaledValue($this->units, self::SCALE)->toString();
    }

    public function currency(): string
    {
        return self::CURRENCY;
    }

    public function equals(self $other): bool
    {
        return $this->units === $other->units;
    }
}
