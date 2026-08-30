<?php

namespace App\Storefront;

use App\Catalog\CatalogFamilyReadModel;

final readonly class StorefrontCatalogFamily
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $imageUrl,
        public int $optionCount,
        public int $availableOptionCount,
        public int $soldOutOptionCount,
        public ?int $minimumPriceAmount,
        public ?int $minimumUsdtPriceAmount,
        public bool $isAvailable,
        public string $familyUrl,
    ) {}

    public static function fromReadModel(CatalogFamilyReadModel $family, string $familyUrl, ?string $fallbackImageUrl = null): self
    {
        return new self(
            id: $family->id,
            name: $family->name,
            slug: $family->slug,
            description: $family->description,
            imageUrl: $family->imageUrl ?? $fallbackImageUrl,
            optionCount: $family->optionCount,
            availableOptionCount: $family->availableOptionCount,
            soldOutOptionCount: $family->soldOutOptionCount,
            minimumPriceAmount: $family->minimumPriceAmount,
            minimumUsdtPriceAmount: $family->minimumUsdtPriceAmount,
            isAvailable: $family->isAvailable,
            familyUrl: $familyUrl,
        );
    }
}
