<?php

namespace App\Storefront;

final readonly class StorefrontCatalogProductPresentation
{
    public function __construct(
        public int $id,
        public string $categoryKey,
        public string $name,
        public string $description,
        public string $descriptionSnippet,
        public StorefrontProductMerchandising $merchandising,
        public int $priceAmount,
        public ?int $usdtPriceAmount,
        public string $currency,
        public int $stockAvailable,
        public int $soldQuantity,
        public bool $isAvailable,
        public string $availabilityLabel,
        public string $stockLabel,
        public string $soldLabel,
        public StorefrontProductCoverPresentation $cover,
        public string $productUrl,
        public string $checkoutUrl,
        public bool $canJoinWaitlist,
        public string $waitlistUrl,
        public string $waitlistCtaLabel,
        public ?string $familyOptionLabel = null,
    ) {}
}
