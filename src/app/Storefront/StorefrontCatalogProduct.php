<?php

namespace App\Storefront;

final readonly class StorefrontCatalogProduct
{
    public function __construct(
        public StorefrontCatalogProductPresentation $presentation,
        public bool $isPopular = false,
    ) {}
}
