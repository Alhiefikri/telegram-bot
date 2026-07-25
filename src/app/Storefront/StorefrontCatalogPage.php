<?php

namespace App\Storefront;

use Illuminate\Support\Collection;

final readonly class StorefrontCatalogPage
{
    public const CONTEXT_HOME = 'home';

    public const CONTEXT_SHOP = 'shop';

    /**
     * @param  array<string, mixed>  $copy
     * @param  array<string, mixed>  $supportContext
     * @param  Collection<int, StorefrontCatalogCategoryFilter>  $categoryFilters
     * @param  Collection<int, StorefrontCatalogProduct>  $products
     * @param  Collection<int, StorefrontCatalogProduct>  $popularProducts
     */
    public function __construct(
        public string $context,
        public StorefrontPresenter $storefront,
        public string $brand,
        public array $copy,
        public string $pageTitle,
        public string $pageDescription,
        public string $canonicalUrl,
        public string $homeUrl,
        public string $shopUrl,
        public ?string $supportHref,
        public string $supportDestination,
        public array $supportContext,
        public bool $hasPublicCategories,
        public Collection $categoryFilters,
        public Collection $products,
        public Collection $popularProducts,
        public ?StorefrontCatalogProduct $featuredProduct,
        public string $search,
        public int $searchMaxLength,
        public string $searchAction,
        public string $clearSearchUrl,
        public string $emptyCategoryTitle,
        public string $emptyCategoryBody,
        public string $emptyProductsTitle,
        public string $emptyProductsBody,
        public ?string $popularEmptyMessage = null,
    ) {}

    public function isHome(): bool
    {
        return $this->context === self::CONTEXT_HOME;
    }

    public function isShop(): bool
    {
        return $this->context === self::CONTEXT_SHOP;
    }

    public function productCount(): int
    {
        return $this->products->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'catalogPage' => $this,
            'storefront' => $this->storefront,
            'brand' => $this->brand,
            'copy' => $this->copy,
            'pageTitle' => $this->pageTitle,
            'pageDescription' => $this->pageDescription,
            'canonicalUrl' => $this->canonicalUrl,
            'homeUrl' => $this->homeUrl,
            'shopUrl' => $this->shopUrl,
            'supportHref' => $this->supportHref,
            'supportDestination' => $this->supportDestination,
            'supportContext' => $this->supportContext,
        ];
    }
}
