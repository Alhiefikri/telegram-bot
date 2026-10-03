<?php

namespace App\Services\Suppliers;

final readonly class SupplierOrderResult
{
    /** @param array<string> $deliveries */
    public function __construct(
        public string $outcome,
        public ?string $orderId = null,
        public ?int $quantity = null,
        public ?int $costMicros = null,
        public array $deliveries = [],
        public ?string $errorCode = null,
        public bool $identityTrusted = false,
        public bool $deliveryTrusted = false,
        public array $accountUnits = [],
        public ?int $requestedQuantity = null,
    ) {}
}
