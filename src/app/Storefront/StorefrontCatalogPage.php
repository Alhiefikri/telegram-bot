<?php

namespace App\Storefront;

use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Support\Collection;

final readonly class StorefrontCatalogPage
{
    public const CONTEXT_HOME = 'home';

    public const CONTEXT_SHOP = 'shop';

    /**
     * @param  array<string, mixed>  $copy
     * @param  array<string, mixed>  $supportContext
     * @param  array<string, mixed>  $pagination
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
        public CheckoutMaintenanceState $checkoutMaintenance,
        public ?string $popularEmptyMessage = null,
        public ?string $selectedCategory = null,
        public string $selectedSort = StorefrontCatalogQuery::DEFAULT_SORT,
        public int $currentPage = 1,
        public int $totalProducts = 0,
        public string $allCategoryUrl = '',
        public string $catalogReturnUrl = '',
        public array $pagination = [],
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

    public function totalProductCount(): int
    {
        return $this->totalProducts > 0 || $this->products->isEmpty()
            ? $this->totalProducts
            : $this->products->count();
    }

    public function hasPagination(): bool
    {
        return (bool) ($this->pagination['hasPages'] ?? false);
    }

    public function productReturnUrl(int $productId): string
    {
        return preg_replace('/#products$/', '#product-'.$productId, $this->catalogReturnUrl)
            ?: $this->catalogReturnUrl;
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
            'checkoutMaintenance' => $this->checkoutMaintenance,
            'checkoutMaintenanceNotice' => StorefrontCheckoutMaintenanceCopy::notice($this->checkoutMaintenance),
        ];
    }
}
