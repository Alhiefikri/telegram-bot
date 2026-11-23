<?php

namespace App\Storefront;

final readonly class StorefrontHomeCatalogFilters
{
    public const DEFAULT_SORT = 'available_first';

    public const SORT_LABELS = [
        self::DEFAULT_SORT => 'available_first',
        'price_asc' => 'price_low_to_high',
        'price_desc' => 'price_high_to_low',
        'name_asc' => 'name_a_to_z',
    ];

    public function __construct(
        public string $sort = self::DEFAULT_SORT,
        public bool $inStockOnly = false,
    ) {}

    public static function fromQuery(array $query): self
    {
        $sort = $query['sort'] ?? null;

        return new self(
            sort: is_string($sort) && isset(self::SORT_LABELS[$sort]) ? $sort : self::DEFAULT_SORT,
            inStockOnly: ($query['in_stock'] ?? null) === '1',
        );
    }

    /** @return array<string, string> */
    public function toUrlParameters(): array
    {
        return ($this->sort === self::DEFAULT_SORT ? [] : ['sort' => $this->sort])
            + ($this->inStockOnly ? ['in_stock' => '1'] : []);
    }
}
