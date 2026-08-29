<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\CheckoutMaintenance\CheckoutMaintenanceState;
use Illuminate\Database\Eloquent\Builder;
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

    private const HOME_PRODUCT_LIMIT = 24;

    public function __construct(
        private readonly StorefrontPresenter $storefront,
        private readonly StorefrontProductPresenter $products,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StorefrontSeo $seo,
    ) {}

    public function home(StorefrontCatalogQuery|string|null $query): StorefrontCatalogPage
    {
        return $this->build(StorefrontCatalogPage::CONTEXT_HOME, $this->coerceQuery($query));
    }

    public function shop(StorefrontCatalogQuery|string|null $query): StorefrontCatalogPage
    {
        return $this->build(StorefrontCatalogPage::CONTEXT_SHOP, $this->coerceQuery($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function homeViewData(StorefrontCatalogQuery|string|null $query): array
    {
        return $this->home($query)->viewData();
    }

    /**
     * @return array<string, mixed>
     */
    public function shopViewData(StorefrontCatalogQuery|string|null $query): array
    {
        return $this->shop($query)->viewData();
    }

    private function build(string $context, StorefrontCatalogQuery $query): StorefrontCatalogPage
    {
        $isShop = $context === StorefrontCatalogPage::CONTEXT_SHOP;
        $maintenance = $this->maintenance->current();
        $categories = $this->publicCategories();
        $category = $this->publicCategory($query->category);
        $query = $query->withCategory($category?->slug);
        $listing = $this->publicProducts($query, paginate: $isShop);
        $query = $query->withPage($listing['page']);
        $catalogProducts = $this->catalogProducts($listing['products'], $maintenance);
        $featuredProduct = $context === StorefrontCatalogPage::CONTEXT_HOME
            ? $this->featuredProduct($maintenance)
            : null;
        $copy = config('storefront');
        $brand = $this->storefront->brand();
        $catalogReturnUrl = $isShop
            ? $this->urls->shopCatalogUrl($query, withFragment: true)
            : $this->urls->catalogUrl(search: $query->search !== '' ? $query->search : null);
        $allCategoryUrl = $isShop
            ? $this->urls->shopCatalogUrl($query->withoutCategory()->withoutPage(), withFragment: true)
            : $this->urls->catalogUrl(search: $query->search !== '' ? $query->search : null);
        $canonicalUrl = $isShop
            ? $this->urls->shopCatalogUrl($query, withFragment: false)
            : url('/');
        $metadata = $isShop
            ? $this->seo->catalog($query, $canonicalUrl)
            : $this->seo->home($canonicalUrl, hasSearch: $query->search !== '');

        return new StorefrontCatalogPage(
            context: $context,
            storefront: $this->storefront,
            brand: $brand,
            copy: $copy,
            pageTitle: $metadata->title,
            pageDescription: $metadata->description,
            canonicalUrl: $metadata->canonicalUrl ?? $canonicalUrl,
            metadata: $metadata,
            homeUrl: route('storefront.home'),
            shopUrl: route('storefront.shop.index'),
            supportHref: $this->storefront->supportHref(),
            supportDestination: $this->storefront->supportDestination(),
            supportContext: $this->storefront->support()->general(),
            hasPublicCategories: $categories->isNotEmpty(),
            categoryFilters: $this->categoryFilters($categories, $query, $isShop),
            products: $catalogProducts,
            popularProducts: $this->orderedPopularProducts($catalogProducts),
            featuredProduct: $featuredProduct,
            search: $query->search,
            searchMaxLength: StorefrontCatalogSearch::MAX_LENGTH,
            searchAction: $isShop
                ? route('storefront.shop.index')
                : $this->urls->catalogUrl(),
            clearSearchUrl: $isShop
                ? $this->urls->shopCatalogUrl($query->withoutSearch()->withoutPage(), withFragment: true)
                : $this->urls->catalogUrl(),
            emptyCategoryTitle: $copy['catalog']['empty_category_title'],
            emptyCategoryBody: $copy['catalog']['empty_category_body'],
            emptyProductsTitle: $isShop ? $copy['shop']['empty_title'] : $copy['catalog']['empty_title'],
            emptyProductsBody: $isShop ? $copy['shop']['empty_body'] : $copy['catalog']['empty_body'],
            checkoutMaintenance: $maintenance,
            popularEmptyMessage: $isShop ? null : $copy['hero']['popular_empty'],
            selectedCategory: $query->category,
            selectedSort: $query->sort,
            currentPage: $query->page,
            totalProducts: $listing['total'],
            allCategoryUrl: $allCategoryUrl,
            catalogReturnUrl: $catalogReturnUrl,
            pagination: $isShop ? $this->pagination($listing['total'], $listing['lastPage'], $query) : [],
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
            ->get();
    }

    private function publicCategory(?string $category): ?Category
    {
        if ($category === null) {
            return null;
        }

        $query = Category::query()
            ->select(['id', 'name', 'slug'])
            ->publicVisible();

        return ctype_digit($category)
            ? $query->whereKey((int) $category)->first()
            : $query->where('slug', $category)->first();
    }

    /**
     * @return array{products: Collection<int, Product>, total: int, page: int, lastPage: int}
     */
    private function publicProducts(StorefrontCatalogQuery $query, bool $paginate): array
    {
        $productQuery = $this->publicProductQuery($query);
        $this->orderProducts($productQuery, $query->sort);

        if (! $paginate) {
            $products = $productQuery->limit(self::HOME_PRODUCT_LIMIT)->get();

            return [
                'products' => $products,
                'total' => $products->count(),
                'page' => 1,
                'lastPage' => 1,
            ];
        }

        $total = (clone $productQuery)->reorder()->count();
        $lastPage = max(1, (int) ceil($total / StorefrontCatalogQuery::PRODUCTS_PER_PAGE));
        $page = min($query->page, $lastPage);
        $products = $productQuery
            ->forPage($page, StorefrontCatalogQuery::PRODUCTS_PER_PAGE)
            ->get();

        return [
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
        ];
    }

    /**
     * @return Builder<Product>
     */
    private function publicProductQuery(StorefrontCatalogQuery $query): Builder
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
            ->when($query->category !== null, fn (Builder $productQuery) => $productQuery
                ->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery
                    ->publicVisible()
                    ->where('slug', $query->category)))
            ->tap(fn (Builder $productQuery) => StorefrontCatalogSearch::apply($productQuery, $query->search));
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function orderProducts(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            'price_asc' => $query
                ->orderBy('price_amount')
                ->orderBy('name')
                ->orderBy('id'),
            'price_desc' => $query
                ->orderByDesc('price_amount')
                ->orderBy('name')
                ->orderBy('id'),
            default => $query->storefrontOrder(),
        };
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
     * @return Collection<int, StorefrontCatalogCategoryFilter>
     */
    private function categoryFilters(Collection $categories, StorefrontCatalogQuery $query, bool $isShop): Collection
    {
        return $categories
            ->map(fn (Category $category): StorefrontCatalogCategoryFilter => new StorefrontCatalogCategoryFilter(
                id: (int) $category->id,
                name: $category->name,
                slug: $category->slug,
                url: $isShop
                    ? $this->urls->shopCatalogUrl($query->withCategory($category->slug)->withoutPage(), withFragment: true)
                    : $this->urls->shopCatalogUrl(StorefrontCatalogQuery::fromSearch($query->search)->withCategory($category->slug), withFragment: true),
                isActive: $query->category === $category->slug,
            ))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function pagination(int $total, int $lastPage, StorefrontCatalogQuery $query): array
    {
        $currentPage = $query->page;
        $pageNumbers = $this->paginationPageNumbers($currentPage, $lastPage);
        $pages = [];
        $previousNumber = null;

        foreach ($pageNumbers as $pageNumber) {
            if ($previousNumber !== null && $pageNumber > $previousNumber + 1) {
                $pages[] = ['ellipsis' => true, 'label' => '…'];
            }

            $pages[] = [
                'ellipsis' => false,
                'label' => (string) $pageNumber,
                'number' => $pageNumber,
                'url' => $this->urls->shopCatalogUrl($query->withPage($pageNumber), withFragment: true),
                'current' => $pageNumber === $currentPage,
            ];
            $previousNumber = $pageNumber;
        }

        return [
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
            'from' => $total === 0 ? null : (($currentPage - 1) * StorefrontCatalogQuery::PRODUCTS_PER_PAGE) + 1,
            'to' => $total === 0 ? null : min($total, $currentPage * StorefrontCatalogQuery::PRODUCTS_PER_PAGE),
            'total' => $total,
            'perPage' => StorefrontCatalogQuery::PRODUCTS_PER_PAGE,
            'hasPages' => $lastPage > 1,
            'previousUrl' => $currentPage > 1
                ? $this->urls->shopCatalogUrl($query->withPage($currentPage - 1), withFragment: true)
                : null,
            'nextUrl' => $currentPage < $lastPage
                ? $this->urls->shopCatalogUrl($query->withPage($currentPage + 1), withFragment: true)
                : null,
            'pages' => $pages,
        ];
    }

    /** @return list<int> */
    private function paginationPageNumbers(int $currentPage, int $lastPage): array
    {
        if ($lastPage <= 7) {
            return range(1, $lastPage);
        }

        return array_values(array_unique([
            1,
            max(1, $currentPage - 1),
            $currentPage,
            min($lastPage, $currentPage + 1),
            $lastPage,
        ]));
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

    private function coerceQuery(StorefrontCatalogQuery|string|null $query): StorefrontCatalogQuery
    {
        return $query instanceof StorefrontCatalogQuery ? $query : StorefrontCatalogQuery::fromSearch($query);
    }
}
