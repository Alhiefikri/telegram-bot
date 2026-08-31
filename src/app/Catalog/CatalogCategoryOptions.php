<?php

namespace App\Catalog;

final readonly class CatalogCategoryOptions
{
    /**
     * @param  list<CatalogCategoryReadModel>  $categories
     */
    public function __construct(
        public array $categories,
        public bool $hasUncategorized,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'categories' => array_map(
                static fn (CatalogCategoryReadModel $category): array => $category->toArray(),
                $this->categories,
            ),
            'has_uncategorized' => $this->hasUncategorized,
        ];
    }
}
