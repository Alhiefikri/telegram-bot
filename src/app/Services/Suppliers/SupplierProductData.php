<?php

namespace App\Services\Suppliers;

final readonly class SupplierProductData
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $description,
        public ?int $unitPriceMicros,
        public ?int $stock,
        public bool $orderable,
        public bool $requiresInput,
        public array $priceTiers = [],
        public array $bulkDiscounts = [],
    ) {}
}
