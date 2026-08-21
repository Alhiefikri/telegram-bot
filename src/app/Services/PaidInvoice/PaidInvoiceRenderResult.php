<?php

namespace App\Services\PaidInvoice;

/**
 * One transient, public-safe paid invoice image.
 *
 * PNG bytes intentionally live only in this in-memory value. Callers may pass
 * them to Telegram and then let the value go out of scope; no persistence
 * boundary is part of this contract.
 */
final readonly class PaidInvoiceRenderResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $bytes,
        public string $filename,
        public int $width,
        public int $height,
        public array $metadata,
    ) {}

    public function png(): string
    {
        return $this->bytes;
    }

    public function contents(): string
    {
        return $this->bytes;
    }

    /**
     * @return array{width: int, height: int}
     */
    public function dimensions(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentation(): array
    {
        return $this->metadata;
    }
}
