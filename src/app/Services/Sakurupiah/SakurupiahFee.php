<?php

namespace App\Services\Sakurupiah;

use InvalidArgumentException;

final readonly class SakurupiahFee
{
    private string $submitted;

    private function __construct(string $submitted)
    {
        $this->submitted = $submitted;
    }

    public static function from(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Sakurupiah fee must be a scalar numeric value.');
        }

        $submitted = is_int($value) ? (string) $value : $value;
        if (strlen($submitted) > 64 || preg_match('/^\d+(?:\.\d+)?%?$/', $submitted) !== 1) {
            throw new InvalidArgumentException('Sakurupiah fee format is invalid.');
        }
        if (str_contains($submitted, '.') && ! str_ends_with($submitted, '%')) {
            throw new InvalidArgumentException('Sakurupiah fixed fee must be a whole value.');
        }

        if (str_ends_with($submitted, '%')) {
            if (self::percentageExceedsHundred(substr($submitted, 0, -1))) {
                throw new InvalidArgumentException('Sakurupiah fee percentage must not exceed 100%.');
            }
        } elseif (! str_contains($submitted, '.')) {
            SakurupiahAmount::from($submitted, allowZero: true);
        }

        return new self($submitted);
    }

    private static function percentageExceedsHundred(string $value): bool
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return strlen($whole) > 3
            || (strlen($whole) === 3 && strcmp($whole, '100') > 0)
            || ($whole === '100' && $fraction !== '');
    }

    public function submitted(): string
    {
        return $this->submitted;
    }

    public function isPercentage(): bool
    {
        return str_ends_with($this->submitted, '%');
    }

    public function percentage(): ?string
    {
        return $this->isPercentage() ? substr($this->submitted, 0, -1) : null;
    }

    public function fixedAmount(): ?int
    {
        if ($this->isPercentage() || preg_match('/^\d+$/', $this->submitted) !== 1) {
            return null;
        }

        return SakurupiahAmount::from($this->submitted, allowZero: true)->value();
    }

    public function __toString(): string
    {
        return $this->submitted;
    }
}
