<?php

namespace App\Storefront;

final readonly class StorefrontCatalogCategoryFilter
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}
}
