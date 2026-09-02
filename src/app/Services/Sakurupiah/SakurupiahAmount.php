<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahAmount
{
    private int $value;

    private string $submitted;

    private function __construct(int $value, string $submitted)
    {
        $this->value = $value;
        $this->submitted = $submitted;
    }

    public static function from(mixed $value, bool $allowZero = false): self
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Sakurupiah amount must be an integer or digit string.');
        }

        $submitted = is_int($value) ? (string) $value : $value;
        if (preg_match('/^\d+$/', $submitted) !== 1) {
            throw new InvalidArgumentException('Sakurupiah amount must be a whole non-negative value.');
        }

        $canonical = ltrim($submitted, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        if (! $allowZero && $canonical === '0') {
            throw new InvalidArgumentException('Sakurupiah amount must be greater than zero.');
        }

        if (self::exceedsPhpIntMax($canonical)) {
            throw new InvalidArgumentException('Sakurupiah amount exceeds the supported integer range.');
        }

        return new self((int) $canonical, $submitted);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function submitted(): string
    {
        return $this->submitted;
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function __toString(): string
    {
        return $this->submitted;
    }

    private static function exceedsPhpIntMax(string $value): bool
    {
        $maximum = (string) PHP_INT_MAX;

        return strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0);
    }
}
