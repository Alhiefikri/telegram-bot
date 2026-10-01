<?php

namespace App\Storefront;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogCategoryReadModel;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Services\Catalog\CatalogBrowse;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use Illuminate\Support\Collection;

class StorefrontCatalogPageBuilder
{
    public function __construct(
        private readonly StorefrontCatalogFacade $storefront,
        private readonly StorefrontCatalogProductPresenter $catalogPresenter,
        private readonly StorefrontUrl $urls,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StorefrontSeo $seo,
        private readonly CatalogBrowse $catalog,
        private readonly StorefrontBanners $banners,
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
        return $this->home($query)->viewData() + ['homepageBanners' => $this->banners->published()];
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
        $query = $query->withCategory($query->category === 'uncategorized' ? 'uncategorized' : $category?->slug);
        $listing = $this->publicProducts($query, paginate: $isShop);
        $catalogProducts = $this->catalogProducts($listing['standaloneReadModels']);
        $catalogEntries = $this->catalogEntries($listing['entries'], $catalogProducts);
        $popularProducts = $isShop ? collect() : $this->orderedPopularProducts($catalogProducts);
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
            supportContext: $this->storefront->generalSupportContext(),
            hasPublicCategories: $categories->isNotEmpty(),
            categoryFilters: $this->categoryFilters($categories, $query, $isShop),
            products: $catalogProducts,
            popularProducts: $popularProducts,
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
            popularEmptyMessage: null,
            selectedCategory: $query->category,
            selectedSort: $query->sort,
            currentPage: $query->page,
            totalProducts: $listing['total'],
            allCategoryUrl: $allCategoryUrl,
            catalogReturnUrl: $catalogReturnUrl,
            pagination: $isShop ? $this->pagination($listing['total'], $listing['lastPage'], $query) : [],
            entries: $catalogEntries,
        );
    }

    /**
     * @return Collection<int, CatalogCategoryReadModel>
     */
    private function publicCategories(): Collection
    {
        return collect($this->catalog->publicCategoryOptions(withProductsOnly: false)->categories);
    }

    private function publicCategory(?string $category): ?CatalogCategoryReadModel
    {
        return $category === null ? null : $this->catalog->findPublicCategory($category);
    }

    /**
     * @return array{standaloneReadModels: Collection<int, CatalogProductReadModel>, entries: list<CatalogBrowseEntry>, total: int, page: int, lastPage: int}
     */
    private function publicProducts(StorefrontCatalogQuery $query, bool $paginate): array
    {
        $result = $this->catalog->browsePublic(CatalogBrowseQuery::fromValues(
            search: $query->search,
            category: $query->category,
            sort: $query->sort,
            page: $query->page,
            perPage: StorefrontCatalogQuery::PRODUCTS_PER_PAGE,
        ));
        $standaloneReadModels = collect($result->standaloneProducts)->keyBy('id');
        $pagination = $result->pagination;

        return [
            'standaloneReadModels' => $standaloneReadModels,
            'entries' => $result->entries,
            'total' => $paginate ? $pagination->total : count($result->entries),
            'page' => $paginate ? $pagination->currentPage : 1,
            'lastPage' => $paginate ? $pagination->lastPage : 1,
        ];
    }

    /**
     * @param  Collection<int, CatalogProductReadModel>  $readModels
     * @return Collection<int, StorefrontCatalogProduct>
     */
    private function catalogProducts(Collection $readModels): Collection
    {
        $presentations = $readModels->mapWithKeys(
            fn (CatalogProductReadModel $readModel): array => [
                $readModel->id => $this->catalogPresenter->present($readModel),
            ],
        );

        /*
         * POPULER is based only on observed paid completed sales. Available
         * products with more sales rank first; name and id make ties stable.
         */
        $popularIds = $presentations
            ->filter(fn (StorefrontCatalogProductPresentation $presentation): bool => $presentation->isAvailable && $presentation->soldQuantity > 0)
            ->sort(fn (StorefrontCatalogProductPresentation $left, StorefrontCatalogProductPresentation $right): int => $this->comparePopular($left, $right))
            ->take(3)
            ->keys()
            ->all();

        return $presentations
            ->map(fn (StorefrontCatalogProductPresentation $presentation): StorefrontCatalogProduct => new StorefrontCatalogProduct(
                presentation: $presentation,
                isPopular: in_array($presentation->id, $popularIds, true),
            ))
            ->values();
    }

    /**
     * @param  list<CatalogBrowseEntry>  $entries
     * @param  Collection<int, StorefrontCatalogProduct>  $catalogProducts
     * @return Collection<int, StorefrontCatalogEntry>
     */
    private function catalogEntries(array $entries, Collection $catalogProducts): Collection
    {
        $products = $catalogProducts->keyBy(fn (StorefrontCatalogProduct $product): int => $product->presentation->id);

        return collect($entries)
            ->map(function (CatalogBrowseEntry $entry) use ($products): ?StorefrontCatalogEntry {
                if ($entry->family instanceof CatalogFamilyReadModel) {
                    $family = $entry->family;

                    return StorefrontCatalogEntry::family(
                        StorefrontCatalogFamily::fromReadModel($family, $this->urls->familyUrl($family->slug)),
                    );
                }
                if ($entry->product === null) {
                    return null;
                }

                $product = $products->get($entry->product->id);

                return $product instanceof StorefrontCatalogProduct
                    ? StorefrontCatalogEntry::product($product)
                    : null;
            })
            ->filter()
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

    private function comparePopular(StorefrontCatalogProductPresentation $left, StorefrontCatalogProductPresentation $right): int
    {
        return ($right->soldQuantity <=> $left->soldQuantity)
            ?: strcmp($left->name, $right->name)
            ?: ($left->id <=> $right->id);
    }

    /**
     * @param  Collection<int, CatalogCategoryReadModel>  $categories
     * @return Collection<int, StorefrontCatalogCategoryFilter>
     */
    private function categoryFilters(Collection $categories, StorefrontCatalogQuery $query, bool $isShop): Collection
    {
        return $categories
            ->map(fn (CatalogCategoryReadModel $category): StorefrontCatalogCategoryFilter => new StorefrontCatalogCategoryFilter(
                id: $category->id,
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

    private function coerceQuery(StorefrontCatalogQuery|string|null $query): StorefrontCatalogQuery
    {
        return $query instanceof StorefrontCatalogQuery ? $query : StorefrontCatalogQuery::fromSearch($query);
    }
}
