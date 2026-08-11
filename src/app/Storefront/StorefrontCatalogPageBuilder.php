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
        $featuredProduct = $search === ''
            ? $this->featuredFromCatalog($catalogProducts)
            : $this->featuredProduct($maintenance);
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
            popularProducts: $catalogProducts->filter->isPopular->values(),
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
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'usdt_price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
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
        $presentations = $products
            ->map(fn (Product $product) => $this->products->present($product, maintenanceState: $maintenance))
            ->values();

        $popularIds = $presentations
            ->filter(fn (StorefrontProductPresentation $presentation) => $presentation->isAvailable)
            ->take(3)
            ->map(fn (StorefrontProductPresentation $presentation) => $presentation->id)
            ->all();

        return $presentations
            ->map(fn (StorefrontProductPresentation $presentation) => new StorefrontCatalogProduct(
                presentation: $presentation,
                isPopular: in_array($presentation->id, $popularIds, true),
            ))
            ->values();
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

    /**
     * Reuse the already-fetched catalog for the featured slot when the catalog is
     * unfiltered. storefrontOrder() sorts available products first and the
     * presentation's isAvailable flag mirrors the availableForPurchase() scope, so
     * the first available entry equals featuredProduct()->first() without a second
     * products query. Search results keep the global featuredProduct() query so the
     * featured slot stays independent of the active filter.
     *
     * @param  Collection<int, StorefrontCatalogProduct>  $catalogProducts
     */
    private function featuredFromCatalog(Collection $catalogProducts): ?StorefrontCatalogProduct
    {
        $featured = $catalogProducts->first(
            fn (StorefrontCatalogProduct $product): bool => $product->presentation->isAvailable,
        );

        return $featured !== null
            ? new StorefrontCatalogProduct($featured->presentation)
            : null;
    }

    private function featuredProduct(CheckoutMaintenanceState $maintenance): ?StorefrontCatalogProduct
    {
        $product = Product::query()
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'usdt_price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
            ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
            ->withAvailability()
            ->withSum(['orderItems as sold_quantity' => fn ($query) => $query
                ->whereHas('order', fn ($order) => $order
                    ->where('order_status', OrderStatus::Completed)
                    ->where('payment_status', PaymentStatus::Paid))], 'quantity')
            ->publicVisible()
            ->availableForPurchase()
            ->storefrontOrder()
            ->first();

        return $product
            ? new StorefrontCatalogProduct($this->products->present($product, maintenanceState: $maintenance))
            : null;
    }
}
