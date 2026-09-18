<?php

namespace App\Storefront;

final readonly class StorefrontCartItemPresentation
{
    public function __construct(
        public int $productId,
        public string $name,
        public string $productUrl,
        public int $quantity,
        public ?int $unitPrice,
        public ?int $subtotal,
        public ?int $usdtUnitPrice,
        public ?int $availableStock,
        public bool $isAvailable,
        public ?string $conflictMessage,
        public ?string $imageUrl = null,
    ) {}

    public function hasConflict(): bool
    {
        return $this->conflictMessage !== null;
    }
}
