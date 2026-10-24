<?php

namespace App\Services\Payments;

/**
 * A resolved QR template: the decorative canvas plus the rectangular area the
 * owner reserved for the payment QR. The reserved area is treated as a square
 * centred inside the rectangle so a QR is never stretched.
 */
final readonly class QrTemplatePlacement
{
    public function __construct(
        public string $templateContents,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public function squareSide(): int
    {
        return min($this->width, $this->height);
    }

    public function squareX(): int
    {
        return $this->x + intdiv($this->width - $this->squareSide(), 2);
    }

    public function squareY(): int
    {
        return $this->y + intdiv($this->height - $this->squareSide(), 2);
    }
}
