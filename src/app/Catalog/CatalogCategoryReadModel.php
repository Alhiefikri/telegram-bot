<?php

namespace App\Catalog;

final readonly class CatalogCategoryReadModel
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public int $sortOrder,
        public ?string $description = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sort_order' => $this->sortOrder,
            'description' => $this->description,
        ];
    }
}
