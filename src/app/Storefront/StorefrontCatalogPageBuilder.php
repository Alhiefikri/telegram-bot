<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Support\Collection;

class StorefrontCatalogPageBuilder
{
    /** @var list<string> */
    private const PUBLIC_PRODUCT_COLUMNS = [
        'id',
        'category_id',
        'name',
        'description',
        'product_type',
        'access_duration',
        'region',
        'account_type',
        'device_limit',
        'included_items',
        'limitations',
        'fulfillment_expectation',
        'warranty_policy',
        'support_policy',
        'price_amount',
        'usdt_price_amount',
        'currency',
        'stock',
        'reserved_stock',
        'image_path',
        'is_active',
        'featured_rank',
    ];

    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontProductPresenter $products,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
    ) {}

    public function home(?string $rawSearch): StorefrontCatalogPage
    {
        return $this->build(StorefrontCatalogPage::CONTEXT_HOME, $rawSearch);
    }

    public function shop(?string $rawSearch): StorefrontCatalogPage
    {
        return $this->build(StorefrontCatalogPage::CONTEXT_SHOP, $rawSearch);
    }

    /**
     * @return array<string, mixed>
     */
    public function homeViewData(?string $rawSearch): array
    {
        return $this->home($rawSearch)->viewData();
    }

    /**
     * @return array<string, mixed>
     */
    public function shopViewData(?string $rawSearch): array
    {
        return $this->shop($rawSearch)->viewData();
    }

    private function build(string $context, ?string $rawSearch): StorefrontCatalogPage
    {
        $search = StorefrontCatalogSearch::normalize($rawSearch);
        $maintenance = $this->maintenance->current();
        $categories = $this->publicCategories();
        $products = $this->publicProducts($search);
        $catalogProducts = $this->catalogProducts($products, $maintenance);
        $featuredProduct = $context === StorefrontCatalogPage::CONTEXT_HOME
            ? $this->featuredProduct($maintenance)
            : null;
        $copy = config('storefront');
        $brand = $this->storefront->brand();
        $isShop = $context === StorefrontCatalogPage::CONTEXT_SHOP;

        return new StorefrontCatalogPage(
            context: $context,
            storefront: $this->storefront,
            brand: $brand,
            copy: $copy,
            pageTitle: $isShop ? 'Belanja Produk Digital | Lhie Store' : str_replace(':brand', $brand, $copy['meta']['title']),
            pageDescription: $isShop
                ? 'Katalog produk digital Lhie Store untuk membeli produk digital dan akun premium langsung dari web dengan QRIS, invoice, dan akses pesanan mandiri.'
                : $copy['meta']['description'],
            canonicalUrl: $isShop ? route('storefront.shop.index') : url('/'),
            homeUrl: route('storefront.home'),
            shopUrl: $this->urls->catalogUrl(),
            supportHref: $this->storefront->supportHref(),
            supportDestination: $this->storefront->supportDestination(),
            supportContext: $this->storefront->support()->general(),
            hasPublicCategories: $categories->isNotEmpty(),
            categoryFilters: $this->categoryFilters($categories, $catalogProducts),
            products: $catalogProducts,
            popularProducts: $this->orderedPopularProducts($catalogProducts),
            featuredProduct: $featuredProduct,
            search: $search,
            searchMaxLength: StorefrontCatalogSearch::MAX_LENGTH,
            searchAction: $this->urls->catalogUrl(),
            clearSearchUrl: $this->urls->catalogUrl(),
            emptyCategoryTitle: $copy['catalog']['empty_category_title'],
            emptyCategoryBody: $copy['catalog']['empty_category_body'],
            emptyProductsTitle: $isShop ? $copy['shop']['empty_title'] : $copy['catalog']['empty_title'],
            emptyProductsBody: $isShop ? $copy['shop']['empty_body'] : $copy['catalog']['empty_body'],
            checkoutMaintenance: $maintenance,
            popularEmptyMessage: $isShop ? null : $copy['hero']['popular_empty'],
        );
    }

    /**
     * @return Collection<int, Category>
     */
    private function publicCategories(): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'slug', 'description', 'sort_order'])
            ->publicVisible()
            ->storefrontOrder()
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function publicProducts(string $search): Collection
    {
        return Product::query()
            ->select(self::PUBLIC_PRODUCT_COLUMNS)
            ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
            ->withAvailability()
            ->withSum(['orderItems as sold_quantity' => fn ($query) => $query
                ->whereHas('order', fn ($order) => $order
                    ->where('order_status', OrderStatus::Completed)
                    ->where('payment_status', PaymentStatus::Paid))], 'quantity')
            ->publicVisible()
            ->tap(fn ($query) => StorefrontCatalogSearch::apply($query, $search))
            ->storefrontOrder()
            ->limit(24)
            ->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, StorefrontCatalogProduct>
     */
    private function catalogProducts(Collection $products, CheckoutMaintenanceState $maintenance): Collection
    {
        $presentations = $products->mapWithKeys(
            fn (Product $product): array => [
                (int) $product->id => $this->products->present($product, maintenanceState: $maintenance),
            ],
        );

        /*
         * POPULER is based only on observed paid completed sales. Available
         * products with more sales rank first; name and id make ties stable.
         */
        $popularIds = $presentations
            ->filter(fn (StorefrontProductPresentation $presentation): bool => $presentation->isAvailable && $presentation->soldQuantity > 0)
            ->sort(fn (StorefrontProductPresentation $left, StorefrontProductPresentation $right): int => $this->comparePopular($left, $right))
            ->take(3)
            ->keys()
            ->all();

        return $presentations
            ->map(fn (StorefrontProductPresentation $presentation): StorefrontCatalogProduct => new StorefrontCatalogProduct(
                presentation: $presentation,
                isPopular: in_array($presentation->id, $popularIds, true),
            ))
            ->values();
    }

    /**
     * @param  Collection<int, StorefrontCatalogProduct>  $catalogProducts
     * @return Collection<int, StorefrontCatalogProduct>
     */
    private function orderedPopularProducts(Collection $catalogProducts): Collection
    {
        return $catalogProducts
            ->filter(fn (StorefrontCatalogProduct $product): bool => $product->isPopular)
            ->sort(fn (StorefrontCatalogProduct $left, StorefrontCatalogProduct $right): int => $this->comparePopular($left->presentation, $right->presentation))
            ->values();
    }

    private function comparePopular(StorefrontProductPresentation $left, StorefrontProductPresentation $right): int
    {
        return ($right->soldQuantity <=> $left->soldQuantity)
            ?: strcmp($left->name, $right->name)
            ?: ($left->id <=> $right->id);
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, StorefrontCatalogProduct>  $products
     * @return Collection<int, StorefrontCatalogCategoryFilter>
     */
    private function categoryFilters(Collection $categories, Collection $products): Collection
    {
        $categoryKeys = $products
            ->map(fn (StorefrontCatalogProduct $product) => $product->presentation->categoryKey)
            ->all();

        return $categories
            ->filter(fn (Category $category) => in_array((string) $category->id, $categoryKeys, true))
            ->map(fn (Category $category) => new StorefrontCatalogCategoryFilter(
                id: (int) $category->id,
                name: $category->name,
                slug: $category->slug,
            ))
            ->values();
    }

    private function featuredProduct(CheckoutMaintenanceState $maintenance): ?StorefrontCatalogProduct
    {
        $product = Product::query()
            ->select(self::PUBLIC_PRODUCT_COLUMNS)
            ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
            ->withAvailability()
            ->withSum(['orderItems as sold_quantity' => fn ($query) => $query
                ->whereHas('order', fn ($order) => $order
                    ->where('order_status', OrderStatus::Completed)
                    ->where('payment_status', PaymentStatus::Paid))], 'quantity')
            ->publicVisible()
            ->availableForPurchase()
            ->whereNotNull('featured_rank')
            ->orderBy('featured_rank')
            ->orderBy('name')
            ->orderBy('id')
            ->first();

        return $product
            ? new StorefrontCatalogProduct($this->products->present($product, maintenanceState: $maintenance))
            : null;
    }
}
