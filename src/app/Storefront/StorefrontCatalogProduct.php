<?php

namespace App\Storefront;

final readonly class StorefrontCatalogProduct
{
    public function __construct(
        public StorefrontProductPresentation $presentation,
        public bool $isPopular = false,
    ) {}
}
