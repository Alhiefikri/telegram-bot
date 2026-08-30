<?php

namespace App\Services\Catalog;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogBrowseResult;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogPagination;
use App\Catalog\CatalogProductReadModel;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class CatalogBrowse
{
    private const ADMIN_PER_PAGE = 20;

    /** @var list<string> */
    private const PRODUCT_COLUMNS = [
        'id',
        'category_id',
        'product_family_id',
        'family_sort_order',
        'family_option_label',
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
        'created_at',
    ];

    /** @var list<string> */
    private const FAMILY_COLUMNS = ['id', 'name', 'slug', 'description', 'image_path', 'sort_order', 'created_at'];

    public function browsePublic(CatalogBrowseQuery|string|null $query = null): CatalogBrowseResult
    {
        return $this->browse($this->coerceQuery($query), public: true);
    }

    public function browseAdmin(CatalogBrowseQuery|string|null $query = null): CatalogBrowseResult
    {
        $query = $this->coerceQuery($query);
        if ($query->perPage === CatalogBrowseQuery::DEFAULT_PER_PAGE) {
            $query = new CatalogBrowseQuery($query->search, $query->category, $query->sort, $query->page, self::ADMIN_PER_PAGE);
        }

        return $this->browse($query, public: false);
    }

    public function showPublicFamily(ProductFamily|int|string $identity): CatalogFamilyReadModel
    {
        $familyQuery = ProductFamily::query()->select(self::FAMILY_COLUMNS);
        $family = $identity instanceof ProductFamily
            ? $familyQuery->whereKey($identity->getKey())->first()
            : (is_int($identity) || ctype_digit((string) $identity)
                ? $familyQuery->whereKey((int) $identity)->first()
                : $familyQuery->where('slug', strtolower(trim((string) $identity)))->first());

        if (! $family instanceof ProductFamily) {
            throw (new ModelNotFoundException)->setModel(ProductFamily::class, [(string) $identity]);
        }

        $products = $this->productQuery(public: true)
            ->where('product_family_id', $family->getKey())
            ->get();
        $members = $products
            ->map(fn (Product $product): CatalogProductReadModel => $this->productReadModel($product))
            ->sort(fn (CatalogProductReadModel $left, CatalogProductReadModel $right): int => $this->compareMembers($left, $right, ''))
            ->values();

        if ($members->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ProductFamily::class, [$family->getKey()]);
        }

        return $this->familyReadModel($family, $members, false, '');
    }

    /**
     * Return all non-deleted families for an admin Product membership control.
     * The returned read models contain only the same credential-free data as
     * browseAdmin; callers must load secret Product Stock Items separately.
     *
     * @return list<CatalogFamilyReadModel>
     */
    public function allAdminFamilies(): array
    {
        $result = $this->browse(new CatalogBrowseQuery(perPage: 100), public: false);

        return $result->families;
    }

    private function browse(CatalogBrowseQuery $query, bool $public): CatalogBrowseResult
    {
        $query = CatalogBrowseQuery::fromValues(
            search: $query->search,
            category: $query->category,
            sort: $query->sort,
            page: $query->page,
            perPage: $query->perPage,
        );
        $products = $this->productQuery($public, $query->category)->get();
        $families = ProductFamily::query()
            ->select(self::FAMILY_COLUMNS)
            ->catalogOrder()
            ->get();

        $productModels = $products
            ->map(fn (Product $product): CatalogProductReadModel => $this->productReadModel($product))
            ->values();
        $familyModels = $families
            ->map(function (ProductFamily $family) use ($productModels, $query, $public): ?CatalogFamilyReadModel {
                $members = $productModels
                    ->filter(fn (CatalogProductReadModel $product): bool => $product->familyId === (int) $family->getKey())
                    ->values();

                if ($members->isEmpty()) {
                    if ($public || ($query->search !== '' && ! $this->matchesFamily($family, $query->search))) {
                        return null;
                    }

                    return $this->familyReadModel($family, $members, true, $query->search);
                }

                $familyMatches = $this->matchesFamily($family, $query->search);
                $memberMatches = $members->filter(fn (CatalogProductReadModel $product): bool => $this->matchesProduct($product, $query->search));
                if ($query->search !== '' && ! $familyMatches && $memberMatches->isEmpty()) {
                    return null;
                }

                $orderedMembers = $members
                    ->map(fn (CatalogProductReadModel $product): CatalogProductReadModel => $this->withMatch($product, $this->matchesProduct($product, $query->search)))
                    ->sort(fn (CatalogProductReadModel $left, CatalogProductReadModel $right): int => $this->compareMembers($left, $right, $query->search))
                    ->values();

                return $this->familyReadModel($family, $orderedMembers, $familyMatches || $memberMatches->isNotEmpty(), $query->search);
            })
            ->filter()
            ->values();

        $standalone = $productModels
            ->filter(fn (CatalogProductReadModel $product): bool => $product->familyId === null)
            ->filter(fn (CatalogProductReadModel $product): bool => $this->matchesProduct($product, $query->search))
            ->map(fn (CatalogProductReadModel $product): CatalogProductReadModel => $this->withMatch($product, $query->search !== ''))
            ->sort(fn (CatalogProductReadModel $left, CatalogProductReadModel $right): int => $this->compareProducts($left, $right, $query->sort))
            ->values();

        $entries = $familyModels
            ->map(fn (CatalogFamilyReadModel $family): CatalogBrowseEntry => CatalogBrowseEntry::family($family))
            ->concat($standalone->map(fn (CatalogProductReadModel $product): CatalogBrowseEntry => CatalogBrowseEntry::product($product)))
            ->sort(fn (CatalogBrowseEntry $left, CatalogBrowseEntry $right): int => $this->compareEntries($left, $right, $query->sort))
            ->values();

        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / $query->perPage));
        $currentPage = min(max(1, $query->page), $lastPage);
        $pageEntries = $entries->forPage($currentPage, $query->perPage)->values()->all();
        $pageFamilies = array_values(array_filter(array_map(
            static fn (CatalogBrowseEntry $entry): ?CatalogFamilyReadModel => $entry->family,
            $pageEntries,
        )));
        $pageStandalone = array_values(array_filter(array_map(
            static fn (CatalogBrowseEntry $entry): ?CatalogProductReadModel => $entry->product,
            $pageEntries,
        )));

        return new CatalogBrowseResult(
            entries: $pageEntries,
            pagination: new CatalogPagination(
                currentPage: $currentPage,
                lastPage: $lastPage,
                total: $total,
                perPage: $query->perPage,
                from: $total === 0 ? null : (($currentPage - 1) * $query->perPage) + 1,
                to: $total === 0 ? null : min($total, $currentPage * $query->perPage),
            ),
            families: $pageFamilies,
            standaloneProducts: $pageStandalone,
            search: $query->search,
            category: $query->category,
        );
    }

    /**
     * @return Builder<Product>
     */
    private function productQuery(bool $public, ?string $category = null): Builder
    {
        return Product::query()
            ->select(self::PRODUCT_COLUMNS)
            ->with([
                'category' => fn ($query) => $query->select(['id', 'name', 'slug', 'is_active']),
                'family' => fn ($query) => $query->select(self::FAMILY_COLUMNS),
            ])
            ->withAvailability()
            ->withSum(['orderItems as sold_quantity' => fn ($query) => $query
                ->whereHas('order', fn ($order) => $order
                    ->where('order_status', OrderStatus::Completed)
                    ->where('payment_status', PaymentStatus::Paid))], 'quantity')
            ->when($public, fn (Builder $query) => $query->publicVisible())
            ->when($category !== null, function (Builder $query) use ($category, $public): void {
                if ($category === 'uncategorized') {
                    $query->whereNull('category_id');

                    return;
                }
                if (ctype_digit($category)) {
                    $query->where('category_id', (int) $category);

                    return;
                }

                $query->whereHas('category', function (Builder $categoryQuery) use ($category, $public): void {
                    $categoryQuery->where('slug', $category);
                    if ($public) {
                        $categoryQuery->where('is_active', true);
                    }
                });
            });
    }

    private function productReadModel(Product $product): CatalogProductReadModel
    {
        $category = $product->category;
        $family = $product->family;

        return new CatalogProductReadModel(
            id: (int) $product->getKey(),
            name: (string) $product->name,
            label: trim((string) ($product->family_option_label ?: $product->name)),
            description: (string) $product->description,
            merchandising: [
                'product_type' => $product->product_type,
                'access_duration' => $product->access_duration,
                'region' => $product->region,
                'account_type' => $product->account_type,
                'device_limit' => $product->device_limit,
                'included_items' => $product->included_items,
                'limitations' => $product->limitations,
                'fulfillment_expectation' => $product->fulfillment_expectation,
                'warranty_policy' => $product->warranty_policy,
                'support_policy' => $product->support_policy,
            ],
            categoryId: $product->category_id === null ? null : (int) $product->category_id,
            categoryName: $category?->name,
            categorySlug: $category?->slug,
            priceAmount: (int) $product->price_amount,
            usdtPriceAmount: $product->usdt_price_amount === null ? null : (int) $product->usdt_price_amount,
            currency: (string) $product->currency,
            stock: (int) $product->stock,
            reservedStock: (int) $product->reserved_stock,
            availableStock: $product->availableStock(),
            soldQuantity: (int) ($product->getAttribute('sold_quantity') ?? 0),
            isActive: (bool) $product->is_active,
            isPublicVisible: $product->isPublicVisible(),
            isAvailable: $product->isAvailable(),
            familyId: $family?->getKey() === null ? null : (int) $family->getKey(),
            familyName: $family?->name,
            familySortOrder: (int) ($product->family_sort_order ?? 0),
            optionLabel: $product->family_option_label,
            imagePath: $product->image_path,
            imageUrl: $this->publicImageUrl($product->image_path),
            createdAt: $product->created_at?->toISOString(),
        );
    }

    /** @param Collection<int, CatalogProductReadModel> $members */
    private function familyReadModel(ProductFamily $family, Collection $members, bool $matchesSearch, string $search): CatalogFamilyReadModel
    {
        $firstMember = $members->first();
        $prices = $members->pluck('priceAmount');
        $usdtPrices = $members->pluck('usdtPriceAmount')->filter(fn (mixed $price): bool => $price !== null);
        $optionCount = $members->count();
        $availableOptionCount = $members->filter(fn (CatalogProductReadModel $member): bool => $member->isAvailable)->count();

        return new CatalogFamilyReadModel(
            id: (int) $family->getKey(),
            name: (string) $family->name,
            slug: (string) $family->slug,
            description: $family->description,
            imagePath: $family->image_path,
            imageUrl: $this->publicImageUrl($family->image_path),
            sortOrder: (int) $family->sort_order,
            categoryId: $firstMember?->categoryId,
            categoryName: $firstMember?->categoryName,
            categorySlug: $firstMember?->categorySlug,
            optionCount: $optionCount,
            availableOptionCount: $availableOptionCount,
            soldOutOptionCount: max(0, $optionCount - $availableOptionCount),
            minimumPriceAmount: $prices->isEmpty() ? null : (int) $prices->min(),
            minimumUsdtPriceAmount: $usdtPrices->isEmpty() ? null : (int) $usdtPrices->min(),
            isAvailable: $availableOptionCount > 0,
            createdAt: $family->created_at?->toISOString(),
            members: $members->all(),
            matchesSearch: $matchesSearch || ($search !== '' && $this->matchesFamily($family, $search)),
        );
    }

    private function matchesFamily(ProductFamily $family, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return $this->contains($family->name, $search)
            || $this->contains($family->slug, $search)
            || $this->contains($family->description, $search);
    }

    private function matchesProduct(CatalogProductReadModel $product, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return collect([
            $product->name,
            $product->description,
            $product->optionLabel,
            $product->categoryName,
            $product->categorySlug,
            ...array_values($product->merchandising),
        ])->contains(fn (mixed $value): bool => $this->contains($value === null ? null : (string) $value, $search));
    }

    private function contains(?string $value, string $search): bool
    {
        return $value !== null && str_contains($this->lower($value), $search);
    }

    private function compareEntries(CatalogBrowseEntry $left, CatalogBrowseEntry $right, string $sort): int
    {
        if ($sort === 'newest') {
            return $this->compareNullableStrings($right->createdAt(), $left->createdAt())
                ?: $this->compareNames($left->name(), $right->name())
                ?: ($left->id() <=> $right->id());
        }

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $comparison = $this->compareNullableInts($left->minimumPriceAmount(), $right->minimumPriceAmount(), $sort === 'price_desc');

            return $comparison ?: $this->compareNames($left->name(), $right->name()) ?: ($left->id() <=> $right->id());
        }

        return ((int) ! $left->isAvailable() <=> (int) ! $right->isAvailable())
            ?: ($left->isFamily() !== $right->isFamily() ? ($left->isFamily() ? -1 : 1) : 0)
            ?: (($left->family?->sortOrder ?? PHP_INT_MAX) <=> ($right->family?->sortOrder ?? PHP_INT_MAX))
            ?: $this->compareNames($left->name(), $right->name())
            ?: ($left->id() <=> $right->id());
    }

    private function compareProducts(CatalogProductReadModel $left, CatalogProductReadModel $right, string $sort): int
    {
        if ($sort === 'newest') {
            return $this->compareNullableStrings($right->createdAt, $left->createdAt)
                ?: $this->compareNames($left->name, $right->name)
                ?: ($left->id <=> $right->id);
        }

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            return $this->compareNullableInts($left->priceAmount, $right->priceAmount, $sort === 'price_desc')
                ?: $this->compareNames($left->name, $right->name)
                ?: ($left->id <=> $right->id);
        }

        return ((int) ! $left->isAvailable <=> (int) ! $right->isAvailable)
            ?: $this->compareNullableStrings($right->createdAt, $left->createdAt)
            ?: $this->compareNames($left->name, $right->name)
            ?: ($left->id <=> $right->id);
    }

    private function compareMembers(CatalogProductReadModel $left, CatalogProductReadModel $right, string $search): int
    {
        if ($search !== '') {
            return ((int) ! $left->matchesSearch <=> (int) ! $right->matchesSearch)
                ?: ($left->familySortOrder <=> $right->familySortOrder)
                ?: $this->compareNames($left->name, $right->name)
                ?: ($left->id <=> $right->id);
        }

        return ($left->familySortOrder <=> $right->familySortOrder)
            ?: $this->compareNames($left->name, $right->name)
            ?: ($left->id <=> $right->id);
    }

    private function compareNullableInts(?int $left, ?int $right, bool $descending): int
    {
        if ($left === null && $right === null) {
            return 0;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }

        return $descending ? ($right <=> $left) : ($left <=> $right);
    }

    private function compareNullableStrings(?string $left, ?string $right): int
    {
        if ($left === $right) {
            return 0;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }

        return strcmp($left, $right);
    }

    private function compareNames(string $left, string $right): int
    {
        return strcmp($this->lower($left), $this->lower($right));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function withMatch(CatalogProductReadModel $product, bool $matches): CatalogProductReadModel
    {
        return new CatalogProductReadModel(
            id: $product->id,
            name: $product->name,
            label: $product->label,
            description: $product->description,
            merchandising: $product->merchandising,
            categoryId: $product->categoryId,
            categoryName: $product->categoryName,
            categorySlug: $product->categorySlug,
            priceAmount: $product->priceAmount,
            usdtPriceAmount: $product->usdtPriceAmount,
            currency: $product->currency,
            stock: $product->stock,
            reservedStock: $product->reservedStock,
            availableStock: $product->availableStock,
            soldQuantity: $product->soldQuantity,
            isActive: $product->isActive,
            isPublicVisible: $product->isPublicVisible,
            isAvailable: $product->isAvailable,
            familyId: $product->familyId,
            familyName: $product->familyName,
            familySortOrder: $product->familySortOrder,
            optionLabel: $product->optionLabel,
            imagePath: $product->imagePath,
            imageUrl: $product->imageUrl,
            createdAt: $product->createdAt,
            matchesSearch: $matches,
        );
    }

    private function publicImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $publicPath = parse_url($path, PHP_URL_PATH);

            return is_string($publicPath) && $publicPath !== '' && ! str_starts_with($publicPath, '//') ? $publicPath : null;
        }

        return '/storage/'.ltrim($path, '/');
    }

    private function coerceQuery(CatalogBrowseQuery|string|null $query): CatalogBrowseQuery
    {
        if ($query instanceof CatalogBrowseQuery) {
            return $query;
        }

        return CatalogBrowseQuery::fromValues(search: $query);
    }
}
