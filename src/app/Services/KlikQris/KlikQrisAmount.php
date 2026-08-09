<?php

namespace App\Services\KlikQris;

use UnexpectedValueException;

final readonly class KlikQrisAmount
{
    private function __construct(
        private int $rupiah,
        private string $display,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new UnexpectedValueException('KlikQRIS amount must be an integer or decimal string.');
        }

        $raw = trim((string) $value);
        if (preg_match('/^([1-9][0-9]*)(?:\.(0+))?$/', $raw, $matches) !== 1) {
            throw new UnexpectedValueException('KlikQRIS amount must be a positive whole-rupiah value.');
        }

        if (self::isGreaterThanPhpIntMax($matches[1])) {
            throw new UnexpectedValueException('KlikQRIS amount exceeds supported integer range.');
        }

        return new self((int) $matches[1], $raw);
    }

    public function rupiah(): int
    {
        return $this->rupiah;
    }

    public function display(): string
    {
        return $this->display;
    }

    private static function isGreaterThanPhpIntMax(string $value): bool
    {
        $max = (string) PHP_INT_MAX;

        return strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0);
    }
}
