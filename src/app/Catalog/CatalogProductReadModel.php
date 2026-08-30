<?php

namespace App\Catalog;

final readonly class CatalogProductReadModel
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public string $description,
        /** @var array<string, ?string> */
        public array $merchandising,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?string $categorySlug,
        public int $priceAmount,
        public ?int $usdtPriceAmount,
        public string $currency,
        public int $stock,
        public int $reservedStock,
        public int $availableStock,
        public int $soldQuantity,
        public bool $isActive,
        public bool $isPublicVisible,
        public bool $isAvailable,
        public ?int $familyId,
        public ?string $familyName,
        public int $familySortOrder,
        public ?string $optionLabel,
        public ?string $imagePath,
        public ?string $imageUrl,
        public ?string $createdAt,
        public bool $matchesSearch = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'merchandising' => $this->merchandising,
            'label' => $this->label,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'category_slug' => $this->categorySlug,
            'price_amount' => $this->priceAmount,
            'usdt_price_amount' => $this->usdtPriceAmount,
            'currency' => $this->currency,
            'stock' => $this->stock,
            'reserved_stock' => $this->reservedStock,
            'available_stock' => $this->availableStock,
            'sold_quantity' => $this->soldQuantity,
            'is_active' => $this->isActive,
            'is_public_visible' => $this->isPublicVisible,
            'is_available' => $this->isAvailable,
            'family_id' => $this->familyId,
            'family_name' => $this->familyName,
            'family_sort_order' => $this->familySortOrder,
            'option_label' => $this->optionLabel,
            'image_path' => $this->imagePath,
            'image_url' => $this->imageUrl,
            'created_at' => $this->createdAt,
            'matches_search' => $this->matchesSearch,
        ];
    }
}
