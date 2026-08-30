<?php

namespace App\Catalog;

final readonly class CatalogBrowseEntry
{
    public function __construct(
        public string $type,
        public ?CatalogFamilyReadModel $family = null,
        public ?CatalogProductReadModel $product = null,
    ) {}

    public static function family(CatalogFamilyReadModel $family): self
    {
        return new self('family', family: $family);
    }

    public static function product(CatalogProductReadModel $product): self
    {
        return new self('product', product: $product);
    }

    public function isFamily(): bool
    {
        return $this->type === 'family';
    }

    public function id(): int
    {
        return $this->family?->id ?? $this->product?->id ?? 0;
    }

    public function name(): string
    {
        return $this->family?->name ?? $this->product?->name ?? '';
    }

    public function isAvailable(): bool
    {
        return $this->family?->isAvailable ?? $this->product?->isAvailable ?? false;
    }

    public function minimumPriceAmount(): ?int
    {
        return $this->family?->minimumPriceAmount ?? $this->product?->priceAmount;
    }

    public function minimumUsdtPriceAmount(): ?int
    {
        return $this->family?->minimumUsdtPriceAmount ?? $this->product?->usdtPriceAmount;
    }

    public function categoryId(): ?int
    {
        return $this->family?->categoryId ?? $this->product?->categoryId;
    }

    public function createdAt(): ?string
    {
        return $this->family?->createdAt ?? $this->product?->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->isFamily()
            ? ['type' => $this->type, 'family' => $this->family?->toArray()]
            : ['type' => $this->type, 'product' => $this->product?->toArray()];
    }
}
