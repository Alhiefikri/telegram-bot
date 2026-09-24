<?php

namespace App\Services\Admin;

use App\Catalog\CatalogBrowseEntry;
use App\Catalog\CatalogBrowseQuery;
use App\Catalog\CatalogFamilyReadModel;
use App\Catalog\CatalogProductReadModel;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Catalog\CatalogBrowse;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Pricing\ProductQuantityPricing;
use App\Storefront\StorefrontProductPlaceholder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Throwable;

final class AdminDashboardProductsData
{
    public function __construct(
        private readonly ProductImageOptimizer $images,
        private readonly StorefrontProductPlaceholder $placeholder,
        private readonly CatalogBrowse $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forProducts(bool $canManageProducts, int $productsPage = 1, bool $includeStockItems = true): array
    {
        $productPage = $this->latestProducts($canManageProducts, $productsPage, $includeStockItems);
        $familyOptions = $this->familyOptions();

        return [
            'canManage' => $canManageProducts,
            'productStoreUrl' => route('admin.products.store'),
            'products' => $productPage['products'],
            'productGroups' => $productPage['productGroups'],
            'pagination' => $productPage['pagination'],
            'placeholder' => [
                'image_url' => $this->placeholder->imageUrl(),
                'is_custom' => $this->placeholder->isCustom(),
                'update_url' => route('admin.storefront-placeholder.update'),
            ],
            'categoryOptions' => $this->categoryOptions(),
            'familyOptions' => $familyOptions,
            'familyManagement' => $this->familyManagement($familyOptions),
            'categoryManagement' => [
                'createUrl' => route('admin.categories.store'),
                'categories' => $canManageProducts ? $this->categoryManagementCategories() : [],
            ],
        ];
    }

    private function latestProducts(bool $includeManageableStockItems, int $productsPage, bool $includeStockItems): array
    {
        $catalog = $this->catalog->browseAdmin(new CatalogBrowseQuery(page: max(1, $productsPage), perPage: 20));
        $productIds = collect($catalog->entries)
            ->flatMap(function (CatalogBrowseEntry $entry): array {
                if ($entry->isFamily()) {
                    return collect($entry->family?->members ?? [])
                        ->map(fn (CatalogProductReadModel $product): int => $product->id)
                        ->all();
                }

                return $entry->product?->id === null ? [] : [$entry->product->id];
            })
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $catalogProducts = collect($catalog->entries)
            ->flatMap(fn (CatalogBrowseEntry $entry): array => $entry->isFamily()
                ? ($entry->family?->members ?? [])
                : ($entry->product === null ? [] : [$entry->product]))
            ->keyBy('id');
        $products = Product::query()
            ->with(['category:id,name', 'family:id,name,telegram_image_path', 'quantityPriceTiers:id,product_id,minimum_quantity,price_amount,usdt_price_amount'])
            ->withExists('stockItems')
            ->withCount(['stockItems as available_stock_items_count' => fn ($query) => $query->whereNull('order_item_id')->whereNull('retired_at')])
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');
        $orderedProducts = new EloquentCollection($productIds
            ->map(fn (int $id): ?Product => $products->get($id))
            ->filter()
            ->values()
            ->all());

        $stockItemSummaries = $includeManageableStockItems && $includeStockItems
            ? $this->stockItemSummaries($productIds)
            : collect();
        if ($includeManageableStockItems && $includeStockItems) {
            $this->loadOrderedStockItems($orderedProducts);
        }

        $placeholderUrl = $this->placeholder->imageUrl();
        $productPayloadsById = $orderedProducts
            ->mapWithKeys(function (Product $product) use ($stockItemSummaries, $includeManageableStockItems, $includeStockItems, $placeholderUrl, $catalogProducts): array {
                $availableStockItemCount = $product->availableStockItemCount();
                $availableStock = $product->availableStock();
                $stockItems = $includeManageableStockItems
                    && $includeStockItems
                    ? $product->stockItems
                        ->map(fn (ProductStockItem $stockItem): array => $this->stockItemPayload($stockItem, $product))
                        ->all()
                    : [];
                $summary = $stockItemSummaries->get($product->id);
                $stockSummary = $includeManageableStockItems && $includeStockItems
                    ? ['available' => (int) ($summary?->available ?? 0), 'reserved' => (int) ($summary?->reserved ?? 0), 'paid' => (int) ($summary?->paid ?? 0)]
                    : ['available' => 0, 'reserved' => 0, 'paid' => 0];
                $stockUrl = route('admin.products.stock.store', ['product' => $product]);
                $stockAccountsUrl = route('admin.products.stock.accounts.store', ['product' => $product]);
                $stockAccountsReviewUrl = route('admin.products.stock.accounts.review', ['product' => $product]);
                $stockReturnUrl = route('admin.dashboard', ['tab' => 'produk']).'#product-'.$product->id;
                $updateUrl = route('admin.products.update', ['product' => $product]);
                $imageUrl = $this->images->publicUrl($product->image_path) ?? $placeholderUrl;
                $telegramImageUrl = $this->images->telegramUrl($product->telegram_image_path);
                $catalogProduct = $catalogProducts->get((int) $product->id);

                return [(int) $product->id => [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'icon' => $product->icon,
                    'description' => $product->description,
                    'warranty_policy' => $product->warranty_policy,
                    'delivery_note' => $product->delivery_note,
                    'usage_guide_title' => $product->usage_guide_title,
                    'usage_guide_url' => $product->usage_guide_url,
                    'category' => $product->category?->name ?? 'Tanpa kategori',
                    'price_amount' => $product->price_amount,
                    'usdt_price_amount' => $this->formatUsdtPrice($product->usdt_price_amount),
                    'quantity_price_tiers' => $this->quantityPriceTierPayload($product),
                    'price' => Money::idr($product->price_amount),
                    'stock' => $product->stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => $availableStock,
                    'available_stock_item_count' => $availableStockItemCount,
                    'sold_quantity' => $catalogProduct?->soldQuantity ?? 0,
                    'family_id' => $product->product_family_id,
                    'family_name' => $product->family?->name,
                    'family_sort_order' => (int) $product->family_sort_order,
                    'family_option_label' => $product->family_option_label,
                    'stock_items_truncated' => (int) ($summary?->total ?? 0) > count($stockItems),
                    'stock_items' => $stockItems,
                    'is_active' => $product->is_active,
                    'featured_rank' => $product->featured_rank,
                    'featured_label' => $product->featured_rank !== null ? 'Pilihan utama #'.$product->featured_rank : null,
                    'status_label' => $product->is_active ? 'Aktif' : 'Pause',
                    'status_badge' => $this->productStatusBadge($product->is_active),
                    'visibility_label' => $product->is_active ? '● Tampil di toko' : '○ Tidak tampil',
                    'visibility_color' => $product->is_active ? '#7BD10A' : '#ef4444',
                    'image_path' => $product->image_path,
                    'telegram_image_path' => $product->telegram_image_path,
                    'telegram_image_url' => $telegramImageUrl,
                    'image_url' => $imageUrl,
                    'visibility_action_label' => $product->is_active ? '⏸ Pause' : '▶ Play',
                    'update_url' => $updateUrl,
                    'delete_url' => route('admin.products.destroy', ['product' => $product]),
                    'stock_url' => $stockUrl,
                    'stock_accounts_url' => $stockAccountsUrl,
                    'stock_accounts_review_url' => $stockAccountsReviewUrl,
                    'visibility_url' => route('admin.products.visibility.update', ['product' => $product]),
                    'share_url' => route('storefront.home').'#product-'.$product->id,
                    'created_at' => $product->created_at?->toDateTimeString(),
                    'family_membership' => [
                        'update_url' => route('admin.products.family.update', ['product' => $product]),
                        'family_id' => $product->product_family_id,
                        'sort_order' => (int) $product->family_sort_order,
                        'option_label' => $product->family_option_label,
                    ],
                    'stock_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'stock' => $product->stock,
                        'stockUrl' => $stockUrl,
                        'stockAccountsUrl' => $stockAccountsUrl,
                        'stockAccountsReviewUrl' => $stockAccountsReviewUrl,
                        'stockReturnUrl' => $stockReturnUrl,
                        'deliveryNote' => $product->delivery_note,
                    ],
                    'edit_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'icon' => $product->icon,
                        'priceAmount' => $product->price_amount,
                        'usdtPriceAmount' => $this->formatUsdtPrice($product->usdt_price_amount),
                        'quantityPriceTiers' => $this->quantityPriceTierPayload($product),
                        'categoryId' => $product->category_id,
                        'description' => $product->description,
                        'warrantyPolicy' => $product->warranty_policy,
                        'deliveryNote' => $product->delivery_note,
                        'usageGuideTitle' => $product->usage_guide_title,
                        'usageGuideUrl' => $product->usage_guide_url,
                        'isActive' => $product->is_active,
                        'telegramImageUrl' => $telegramImageUrl,
                        'imageUrl' => $imageUrl,
                        'updateUrl' => $updateUrl,
                        'stockUrl' => $stockUrl,
                        'stockAccountsUrl' => $stockAccountsUrl,
                        'stockAccountsReviewUrl' => $stockAccountsReviewUrl,
                        'stockReturnUrl' => $stockReturnUrl,
                        'stock' => $product->stock,
                        'availableStockItemCount' => $availableStockItemCount,
                        'stockSummary' => $stockSummary,
                        'stockItemsTruncated' => (int) ($summary?->total ?? 0) > count($stockItems),
                        'stockItems' => $stockItems,
                        'stockItemsUrl' => route('admin.products.stock-items.index', ['product' => $product]),
                    ],
                ]];
            });

        $productPayload = fn (?CatalogProductReadModel $product): ?array => $product === null
            ? null
            : $productPayloadsById->get($product->id);
        $flatPayloads = collect($catalog->entries)
            ->flatMap(function (CatalogBrowseEntry $entry) use ($productPayload): array {
                if ($entry->isFamily()) {
                    return collect($entry->family?->members ?? [])
                        ->map(fn (CatalogProductReadModel $product): ?array => $productPayload($product))
                        ->filter()
                        ->all();
                }

                $payload = $productPayload($entry->product);

                return $payload === null ? [] : [$payload];
            })
            ->values()
            ->all();
        $productGroups = collect($catalog->entries)
            ->map(function (CatalogBrowseEntry $entry) use ($productPayload): ?array {
                if ($entry->isFamily() && $entry->family instanceof CatalogFamilyReadModel) {
                    $family = $entry->family;
                    $members = collect($family->members)
                        ->map(fn (CatalogProductReadModel $product): ?array => $productPayload($product))
                        ->filter()
                        ->values()
                        ->all();

                    return $this->familyGroupPayload($family, $members);
                }

                $payload = $productPayload($entry->product);

                return $payload === null ? null : $this->singleProductGroupPayload($payload);
            })
            ->filter()
            ->values()
            ->all();
        $pagination = $catalog->pagination;
        $query = collect(request()->query())->except(['tab', 'products_page'])->all();
        $pageUrl = fn (int $page): string => route('admin.dashboard', ['tab' => 'produk', 'products_page' => $page] + $query);

        return [
            'products' => $flatPayloads,
            'productGroups' => $productGroups,
            'families' => $catalog->families,
            'pagination' => [
                'currentPage' => $pagination->currentPage,
                'lastPage' => $pagination->lastPage,
                'previousUrl' => $pagination->currentPage > 1 ? $pageUrl($pagination->currentPage - 1) : null,
                'nextUrl' => $pagination->currentPage < $pagination->lastPage ? $pageUrl($pagination->currentPage + 1) : null,
                'pages' => collect(range(1, $pagination->lastPage))
                    ->map(fn (int $page): array => [
                        'number' => $page,
                        'url' => $pageUrl($page),
                        'current' => $page === $pagination->currentPage,
                    ])
                    ->all(),
            ],
        ];
    }

    /**
     * Load the bounded stock history only after an Admin opens its management
     * modal. Product-list requests otherwise avoid hydrating credentials and
     * their Order/Payment relations.
     *
     * @return array{stockItems: list<array<string, mixed>>, availableStockItemCount: int, stockSummary: array{available: int, reserved: int, paid: int}, stockItemsTruncated: bool}
     */
    public function stockManagementFor(Product $product): array
    {
        $summary = $this->stockItemSummaries(collect([(int) $product->getKey()]))->get($product->getKey());
        $products = new EloquentCollection([$product]);
        $this->loadOrderedStockItems($products);
        $stockItems = $product->stockItems
            ->map(fn (ProductStockItem $stockItem): array => $this->stockItemPayload($stockItem, $product))
            ->all();
        $total = (int) ($summary?->total ?? 0);

        return [
            'stockItems' => $stockItems,
            'availableStockItemCount' => (int) ($summary?->available ?? 0),
            'stockSummary' => [
                'available' => (int) ($summary?->available ?? 0),
                'reserved' => (int) ($summary?->reserved ?? 0),
                'paid' => (int) ($summary?->paid ?? 0),
            ],
            'stockItemsTruncated' => $total > count($stockItems),
        ];
    }

    /** @param Collection<int, int> $productIds */
    private function stockItemSummaries(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return ProductStockItem::query()
            ->whereIn('product_stock_items.product_id', $productIds->all())
            ->leftJoin('order_items', 'product_stock_items.order_item_id', '=', 'order_items.id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->select('product_stock_items.product_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when product_stock_items.order_item_id is null and product_stock_items.retired_at is null then 1 else 0 end) as available')
            ->selectRaw('sum(case when orders.order_status = ? and orders.payment_status <> ? and not exists (select 1 from payments where payments.order_id = orders.id and payments.status = ?) then 1 else 0 end) as reserved', [OrderStatus::PendingPayment->value, PaymentStatus::Paid->value, PaymentStatus::Paid->value])
            ->selectRaw('sum(case when orders.payment_status = ? or exists (select 1 from payments where payments.order_id = orders.id and payments.status = ?) then 1 else 0 end) as paid', [PaymentStatus::Paid->value, PaymentStatus::Paid->value])
            ->groupBy('product_stock_items.product_id')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function familyGroupPayload(CatalogFamilyReadModel $family, array $members): array
    {
        $updateUrl = route('admin.product-families.update', ['productFamily' => $family->id]);

        return [
            'id' => $family->id,
            'name' => $family->name,
            'slug' => $family->slug,
            'description' => $family->description,
            'image_path' => $family->imagePath,
            'image_url' => $family->imageUrl,
            'sort_order' => $family->sortOrder,
            'telegram_image_path' => $family->telegramImagePath,
            'category' => $family->categoryName ?? 'Tanpa kategori',
            'member_count' => $family->optionCount,
            'available_member_count' => $family->availableOptionCount,
            'sold_out_member_count' => $family->soldOutOptionCount,
            'minimum_price_amount' => $family->minimumPriceAmount,
            'minimum_price' => $family->minimumPriceAmount === null ? null : Money::idr($family->minimumPriceAmount),
            'minimum_usdt_price_amount' => $family->minimumUsdtPriceAmount,
            'minimum_usdt_price' => $this->formatUsdtPrice($family->minimumUsdtPriceAmount),
            'is_available' => $family->isAvailable,
            'members' => $members,
            'is_single_product_group' => false,
            'update_url' => $updateUrl,
            'delete_url' => route('admin.product-families.destroy', ['productFamily' => $family->id]),
            'delete_confirmation' => $this->familyDeleteConfirmation($family),
            'edit_payload' => [
                'id' => $family->id,
                'slug' => $family->slug,
                'name' => $family->name,
                'description' => $family->description,
                'sortOrder' => $family->sortOrder,
                'telegramImageUrl' => $family->telegramImagePath === null ? null : $this->images->telegramUrl($family->telegramImagePath),
                'imageUrl' => $family->imageUrl,
                'updateUrl' => $updateUrl,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function singleProductGroupPayload(array $product): array
    {
        return [
            'id' => 'product-'.$product['id'],
            'name' => $product['name'],
            'slug' => null,
            'description' => $product['description'],
            'image_path' => $product['image_path'],
            'image_url' => $product['image_url'],
            'sort_order' => PHP_INT_MAX,
            'category' => $product['category'],
            'member_count' => 1,
            'available_member_count' => (int) ($product['available_stock'] ?? 0) > 0 ? 1 : 0,
            'sold_out_member_count' => (int) ($product['available_stock'] ?? 0) > 0 ? 0 : 1,
            'minimum_price_amount' => $product['price_amount'],
            'minimum_price' => $product['price'],
            'minimum_usdt_price_amount' => $product['usdt_price_amount'],
            'minimum_usdt_price' => $product['usdt_price_amount'],
            'members' => [$product],
            'update_url' => null,
            'delete_url' => null,
            'delete_confirmation' => null,
            'edit_payload' => null,
            'is_single_product_group' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function familyOptions(): array
    {
        return collect($this->catalog->allAdminFamilies())
            ->map(fn (CatalogFamilyReadModel $family): array => [
                'id' => $family->id,
                'name' => $family->name,
                'slug' => $family->slug,
                'description' => $family->description,
                'image_url' => $family->imageUrl,
                'telegram_image_url' => $family->telegramImagePath === null ? null : $this->images->telegramUrl($family->telegramImagePath),
                'category' => $family->categoryName ?? 'Tanpa kategori',
                'sort_order' => $family->sortOrder,
                'member_count' => $family->optionCount,
                'available_member_count' => $family->availableOptionCount,
                'sold_out_member_count' => $family->soldOutOptionCount,
                'minimum_price_amount' => $family->minimumPriceAmount,
                'minimum_price' => $family->minimumPriceAmount === null ? null : Money::idr($family->minimumPriceAmount),
                'minimum_usdt_price_amount' => $family->minimumUsdtPriceAmount,
                'minimum_usdt_price' => $this->formatUsdtPrice($family->minimumUsdtPriceAmount),
                'is_available' => $family->isAvailable,
                'edit_payload' => [
                    'id' => $family->id,
                    'slug' => $family->slug,
                    'name' => $family->name,
                    'description' => $family->description,
                    'sortOrder' => $family->sortOrder,
                    'telegramImageUrl' => $family->telegramImagePath === null ? null : $this->images->telegramUrl($family->telegramImagePath),
                    'imageUrl' => $family->imageUrl,
                    'updateUrl' => route('admin.product-families.update', ['productFamily' => $family->id]),
                ],
            ])
            ->all();
    }

    /** @param list<array<string, mixed>> $families */
    private function familyManagement(array $families): array
    {
        return [
            'createUrl' => route('admin.product-families.store'),
            'families' => array_map(function (array $family): array {
                return $family + [
                    'update_url' => route('admin.product-families.update', ['productFamily' => $family['id']]),
                    'delete_url' => route('admin.product-families.destroy', ['productFamily' => $family['id']]),
                    'delete_confirmation' => $this->familyDeleteConfirmationFromPayload($family),
                ];
            }, $families),
        ];
    }

    private function familyDeleteConfirmation(CatalogFamilyReadModel $family): string
    {
        return $this->familyDeleteConfirmationFromPayload([
            'name' => $family->name,
            'member_count' => $family->optionCount,
        ]);
    }

    /** @param array<string, mixed> $family */
    private function familyDeleteConfirmationFromPayload(array $family): string
    {
        return 'Hapus Product Family '.(string) ($family['name'] ?? '').'? '
            .(int) ($family['member_count'] ?? 0)
            .' Product tidak lagi tergabung dalam family. Visibility, stok, credentials, waitlist, notifikasi, dan history tetap dipertahankan.';
    }

    /** @param Collection<int, Product> $products */
    private function loadOrderedStockItems(Collection $products): void
    {
        $stockItemsTable = (new ProductStockItem)->getTable();

        $products->load(['stockItems' => function ($query) use ($stockItemsTable): void {
            $query
                ->with(['orderItem.order.payments'])
                ->leftJoin('order_items', "{$stockItemsTable}.order_item_id", '=', 'order_items.id')
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->select("{$stockItemsTable}.*")
                ->orderByRaw(
                    "CASE
                        WHEN {$stockItemsTable}.order_item_id IS NULL THEN 0
                        WHEN orders.payment_status = ?
                            OR EXISTS (
                                SELECT 1
                                FROM payments
                                WHERE payments.order_id = orders.id
                                    AND payments.status = ?
                            )
                            THEN 3
                        WHEN orders.order_status = ? THEN 1
                        ELSE 2
                    END",
                    [PaymentStatus::Paid->value, PaymentStatus::Paid->value, OrderStatus::PendingPayment->value],
                )
                ->orderByDesc("{$stockItemsTable}.id")
                ->limit(25);
        }]);
    }

    private function stockItemPayload(ProductStockItem $stockItem, Product $product): array
    {
        $order = $stockItem->orderItem?->order;
        $payment = $order?->payments?->sortByDesc('paid_at')->first();
        $format = $stockItem->safeCredentialFormat();
        $structuredPayload = $format === CredentialFormat::StructuredAccount
            ? $this->structuredPayload($stockItem)
            : null;
        $legacySecret = $format === CredentialFormat::Legacy
            ? $this->legacySecret($stockItem)
            : null;
        $status = $this->stockItemStatus($stockItem, $order, $payment, $format, $structuredPayload, $legacySecret);
        $available = $stockItem->order_item_id === null && $stockItem->retired_at === null;
        $editable = $available
            && (($format === CredentialFormat::Legacy && $legacySecret !== null)
                || ($format === CredentialFormat::StructuredAccount && $structuredPayload !== null));

        return [
            'id' => $stockItem->id,
            'credential_format' => $format?->value ?? 'repair_required',
            'credential_payload' => $structuredPayload,
            'access_domain' => $this->accessDomain($structuredPayload),
            'secret' => $legacySecret,
            'status' => $status,
            'invoice' => $order?->invoice_number,
            'reserved_at' => $order?->created_at?->toDateTimeString(),
            'expires_at' => $order?->expires_at?->toDateTimeString(),
            'paid_at' => $payment?->paid_at?->toDateTimeString(),
            'metadata' => $this->stockItemMetadata($order, $payment, $status),
            'repair_needed' => ($stockItem->order_item_id !== null && in_array($status, ['EXPIRED', 'CANCELLED'], true)) || $status === 'REPAIR',
            'editable' => $editable,
            'update_url' => $editable ? route('admin.products.stock-items.update', ['product' => $product, 'stockItem' => $stockItem]) : null,
            'delete_url' => $editable ? route('admin.products.stock-items.destroy', ['product' => $product, 'stockItem' => $stockItem]) : null,
            'cancel_release_url' => $status === 'DIPESAN' && $order ? route('admin.orders.cancel-release', $order) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredPayload
     */
    private function stockItemStatus(
        ProductStockItem $stockItem,
        $order,
        $payment,
        ?CredentialFormat $format,
        ?array $structuredPayload,
        ?string $legacySecret,
    ): string {
        if (
            $format === null
            || ($format === CredentialFormat::StructuredAccount && $structuredPayload === null)
            || ($format === CredentialFormat::Legacy && $legacySecret === null)
        ) {
            return 'REPAIR';
        }

        if ($stockItem->retired_at !== null) {
            return 'RETIRED';
        }

        if (! $order) {
            return 'TERSEDIA';
        }

        if ($order->payment_status === PaymentStatus::Paid || $payment?->status === PaymentStatus::Paid) {
            return 'PAID';
        }

        return match ($order->order_status) {
            OrderStatus::PendingPayment => 'DIPESAN',
            OrderStatus::Expired => 'EXPIRED',
            OrderStatus::Cancelled => 'CANCELLED',
            default => strtoupper($order->payment_status->value),
        };
    }

    private function stockItemMetadata($order, $payment, string $status): ?string
    {
        if ($status === 'RETIRED') {
            return 'retired · read-only';
        }
        if ($status === 'REPAIR') {
            return 'payload rusak · read-only';
        }

        if (! $order) {
            return null;
        }

        return match ($status) {
            'DIPESAN' => $order->invoice_number.' · expire '.$order->expires_at?->diffForHumans(),
            'PAID' => $order->invoice_number.' · paid '.$payment?->paid_at?->format('d M H:i'),
            'EXPIRED', 'CANCELLED' => $order->invoice_number.' · perlu repair',
            default => $order->invoice_number,
        };
    }

    private function legacySecret(ProductStockItem $stockItem): ?string
    {
        try {
            $secret = $stockItem->secret;
        } catch (Throwable) {
            return null;
        }

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    /**
     * The dashboard is an authenticated operator surface, so Available
     * structured rows may be edited there. Keep this shape explicit and do
     * not leak persistence/cast internals into the Alpine contract.
     *
     * @return array<string, mixed>|null
     */
    private function structuredPayload(ProductStockItem $stockItem): ?array
    {
        try {
            $payload = $stockItem->credential_payload;
            if (! is_array($payload)) {
                return null;
            }

            return StructuredCredentialPayload::fromArray($payload)->toArray();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $structuredPayload
     */
    private function accessDomain(?array $structuredPayload): ?string
    {
        if (($structuredPayload['access_method'] ?? null) !== 'otp_mail_link') {
            return null;
        }

        try {
            return StructuredCredentialPayload::fromArray($structuredPayload)->otpMailDomain();
        } catch (Throwable) {
            return null;
        }
    }

    private function productStatusBadge(bool $isActive): array
    {
        return [
            'background' => $isActive ? '#e8ffd4' : '#f1f5f9',
            'color' => $isActive ? '#166534' : '#64748b',
        ];
    }

    private function categoryOptions(): array
    {
        return Category::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();
    }

    private function formatUsdtPrice(?int $microAmount): ?string
    {
        if ($microAmount === null) {
            return null;
        }

        return rtrim(rtrim(number_format($microAmount / 1_000_000, 6, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @return list<array{minimum_quantity:int, price_amount:int, usdt_price_amount:?string}>
     */
    private function quantityPriceTierPayload(Product $product): array
    {
        return $product->quantityPriceTiers
            ->take(ProductQuantityPricing::MAX_TIERS)
            ->map(fn ($tier): array => [
                'minimum_quantity' => (int) $tier->minimum_quantity,
                'price_amount' => (int) $tier->price_amount,
                'usdt_price_amount' => $tier->usdt_price_amount === null
                    ? null
                    : $this->formatUsdtPrice((int) $tier->usdt_price_amount),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryManagementCategories(): array
    {
        return Category::query()
            ->withCount(['products' => fn ($query) => $query->withTrashed()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'sort_order' => $category->sort_order,
                'product_count' => (int) $category->products_count,
                'status_label' => $category->is_active ? 'Tampil' : 'Disembunyikan',
                'status_color' => $category->is_active ? '#166534' : '#991b1b',
                'update_url' => route('admin.categories.update', ['category' => $category]),
                'delete_url' => route('admin.categories.destroy', ['category' => $category]),
                'delete_confirmation' => $this->deleteConfirmation($category->name, (int) $category->products_count),
                'edit_payload' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'isActive' => $category->is_active,
                    'sortOrder' => $category->sort_order,
                    'updateUrl' => route('admin.categories.update', ['category' => $category]),
                ],
            ])
            ->all();
    }

    private function deleteConfirmation(string $name, int $productCount): string
    {
        return "Hapus kategori {$name}? {$productCount} produk akan menjadi Tanpa kategori. Status aktif, stok, dan reservasi produk tetap dipertahankan.";
    }
}
