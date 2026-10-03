<?php

namespace App\Services\Suppliers;

final readonly class SupplierCatalogSnapshot
{
    /** @param array<SupplierProductData> $products */
    public function __construct(
        public int $balanceMicros,
        public string $currency,
        public array $products,
        public array $accountMetadata = [],
    ) {}
}
