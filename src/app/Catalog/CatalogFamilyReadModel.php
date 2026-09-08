<?php

namespace App\Catalog;

final readonly class CatalogFamilyReadModel
{
    /**
     * @param  list<CatalogProductReadModel>  $members
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $imagePath,
        public ?string $imageUrl,
        public int $sortOrder,
        public ?int $categoryId,
        public ?string $categoryName,
        public ?string $categorySlug,
        public int $optionCount,
        public int $availableOptionCount,
        public int $soldOutOptionCount,
        public ?int $minimumPriceAmount,
        public ?int $minimumUsdtPriceAmount,
        public bool $isAvailable,
        public ?string $createdAt,
        public array $members,
        public ?string $telegramImagePath = null,
        public bool $matchesSearch = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_path' => $this->imagePath,
            'image_url' => $this->imageUrl,
            'sort_order' => $this->sortOrder,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'category_slug' => $this->categorySlug,
            'option_count' => $this->optionCount,
            'available_option_count' => $this->availableOptionCount,
            'sold_out_option_count' => $this->soldOutOptionCount,
            'minimum_price_amount' => $this->minimumPriceAmount,
            'minimum_usdt_price_amount' => $this->minimumUsdtPriceAmount,
            'is_available' => $this->isAvailable,
            'created_at' => $this->createdAt,
            'members' => array_map(static fn (CatalogProductReadModel $member): array => $member->toArray(), $this->members),
            'matches_search' => $this->matchesSearch,
        ];
    }
}
