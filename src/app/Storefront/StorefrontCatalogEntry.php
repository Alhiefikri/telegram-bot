<?php

namespace App\Storefront;

final readonly class StorefrontCatalogEntry
{
    public function __construct(
        public string $type,
        public ?StorefrontCatalogFamily $family = null,
        public ?StorefrontCatalogProduct $product = null,
    ) {}

    public static function family(StorefrontCatalogFamily $family): self
    {
        return new self('family', family: $family);
    }

    public static function product(StorefrontCatalogProduct $product): self
    {
        return new self('product', product: $product);
    }

    public function isFamily(): bool
    {
        return $this->type === 'family';
    }
}
