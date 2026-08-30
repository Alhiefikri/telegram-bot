<?php

namespace App\Catalog;

final readonly class CatalogBrowseResult
{
    /**
     * @param  list<CatalogBrowseEntry>  $entries
     * @param  list<CatalogFamilyReadModel>  $families
     * @param  list<CatalogProductReadModel>  $standaloneProducts
     */
    public function __construct(
        public array $entries,
        public CatalogPagination $pagination,
        public array $families = [],
        public array $standaloneProducts = [],
        public string $search = '',
        public ?string $category = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entries' => array_map(static fn (CatalogBrowseEntry $entry): array => $entry->toArray(), $this->entries),
            'pagination' => $this->pagination->toArray(),
            'families' => array_map(static fn (CatalogFamilyReadModel $family): array => $family->toArray(), $this->families),
            'standalone_products' => array_map(static fn (CatalogProductReadModel $product): array => $product->toArray(), $this->standaloneProducts),
            'search' => $this->search,
            'category' => $this->category,
        ];
    }
}
