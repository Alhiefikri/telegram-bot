<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Support\Money;
use Illuminate\Support\Collection;

final class AdminDashboardProductsData
{
    public function __construct(private readonly ProductImageOptimizer $images) {}

    /**
     * @return array<string, mixed>
     */
    public function forProducts(bool $canManageProducts, int $productsPage = 1): array
    {
        $productPage = $this->latestProducts($canManageProducts, $productsPage);

        return [
            'canManage' => $canManageProducts,
            'productStoreUrl' => route('admin.products.store'),
            'products' => $productPage['products'],
            'pagination' => $productPage['pagination'],
            'categoryOptions' => $this->categoryOptions(),
        ];
    }

    private function latestProducts(bool $includeManageableStockItems, int $productsPage): array
    {
        $paginator = Product::query()
            ->with('category:id,name')
            ->select(['id', 'category_id', 'name', 'description', 'delivery_note', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'stock', 'reserved_stock', 'image_path', 'is_active', 'created_at'])
            ->withExists('stockItems')
            ->withCount(['stockItems as available_stock_items_count' => fn ($query) => $query->whereNull('order_item_id')->whereNull('retired_at')])
            ->latest()
            ->paginate(perPage: 20, pageName: 'products_page', page: max(1, $productsPage))
            ->withQueryString();
        $products = $paginator->getCollection();

        $productIds = $products->pluck('id')->all();
        $stockItemsTable = (new ProductStockItem)->getTable();
        $stockItemSummaries = $includeManageableStockItems
            ? ProductStockItem::query()
                ->whereIn("{$stockItemsTable}.product_id", $productIds)
                ->leftJoin('order_items', "{$stockItemsTable}.order_item_id", '=', 'order_items.id')
                ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
                ->select("{$stockItemsTable}.product_id")
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when {$stockItemsTable}.order_item_id is null and {$stockItemsTable}.retired_at is null then 1 else 0 end) as available")
                ->selectRaw('sum(case when orders.order_status = ? and orders.payment_status <> ? and not exists (select 1 from payments where payments.order_id = orders.id and payments.status = ?) then 1 else 0 end) as reserved', [OrderStatus::PendingPayment->value, PaymentStatus::Paid->value, PaymentStatus::Paid->value])
                ->selectRaw('sum(case when orders.payment_status = ? or exists (select 1 from payments where payments.order_id = orders.id and payments.status = ?) then 1 else 0 end) as paid', [PaymentStatus::Paid->value, PaymentStatus::Paid->value])
                ->groupBy("{$stockItemsTable}.product_id")
                ->get()
                ->keyBy('product_id')
            : collect();
        if ($includeManageableStockItems) {
            $this->loadOrderedStockItems($products);
        }

        $productPayloads = $products
            ->map(function (Product $product) use ($stockItemSummaries, $includeManageableStockItems): array {
                $availableStockItemCount = $product->availableStockItemCount();
                $availableStock = $product->availableStock();
                $stockItems = $includeManageableStockItems
                    ? $product->stockItems
                        ->map(fn (ProductStockItem $stockItem): array => $this->stockItemPayload($stockItem, $product))
                        ->all()
                    : [];
                $summary = $stockItemSummaries->get($product->id);
                $stockSummary = $includeManageableStockItems
                    ? ['available' => (int) ($summary?->available ?? 0), 'reserved' => (int) ($summary?->reserved ?? 0), 'paid' => (int) ($summary?->paid ?? 0)]
                    : ['available' => 0, 'reserved' => 0, 'paid' => 0];
                $stockUrl = route('admin.products.stock.store', ['product' => $product]);
                $updateUrl = route('admin.products.update', ['product' => $product]);

                return [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'delivery_note' => $product->delivery_note,
                    'usage_guide_title' => $product->usage_guide_title,
                    'usage_guide_url' => $product->usage_guide_url,
                    'category' => $product->category?->name ?? 'Tanpa kategori',
                    'price_amount' => $product->price_amount,
                    'usdt_price_amount' => $product->usdt_price_amount === null ? null : number_format($product->usdt_price_amount / 1_000_000, 6, '.', ''),
                    'price' => Money::idr($product->price_amount),
                    'stock' => $product->stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => $availableStock,
                    'available_stock_item_count' => $availableStockItemCount,
                    'stock_items_truncated' => (int) ($summary?->total ?? 0) > count($stockItems),
                    'stock_items' => $stockItems,
                    'is_active' => $product->is_active,
                    'status_label' => $product->is_active ? 'Aktif' : 'Pause',
                    'status_badge' => $this->productStatusBadge($product->is_active),
                    'visibility_label' => $product->is_active ? '● Tampil di toko' : '○ Tidak tampil',
                    'visibility_color' => $product->is_active ? '#7BD10A' : '#ef4444',
                    'image_path' => $product->image_path,
                    'image_url' => $this->images->publicUrl($product->image_path),
                    'visibility_action_label' => $product->is_active ? '⏸ Pause' : '▶ Play',
                    'update_url' => $updateUrl,
                    'delete_url' => route('admin.products.destroy', ['product' => $product]),
                    'stock_url' => $stockUrl,
                    'visibility_url' => route('admin.products.visibility.update', ['product' => $product]),
                    'share_url' => route('storefront.home').'#product-'.$product->id,
                    'created_at' => $product->created_at?->toDateTimeString(),
                    'stock_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'stock' => $product->stock,
                        'stockUrl' => $stockUrl,
                    ],
                    'edit_payload' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'priceAmount' => $product->price_amount,
                        'usdtPriceAmount' => $product->usdt_price_amount === null ? null : number_format($product->usdt_price_amount / 1_000_000, 6, '.', ''),
                        'categoryId' => $product->category_id,
                        'description' => $product->description,
                        'deliveryNote' => $product->delivery_note,
                        'usageGuideTitle' => $product->usage_guide_title,
                        'usageGuideUrl' => $product->usage_guide_url,
                        'isActive' => $product->is_active,
                        'imageUrl' => $this->images->publicUrl($product->image_path),
                        'updateUrl' => $updateUrl,
                        'stockUrl' => $stockUrl,
                        'stock' => $product->stock,
                        'availableStockItemCount' => $availableStockItemCount,
                        'stockSummary' => $stockSummary,
                        'stockItemsTruncated' => (int) ($summary?->total ?? 0) > count($stockItems),
                        'stockItems' => $stockItems,
                    ],
                ];
            })
            ->all();

        return [
            'products' => $productPayloads,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'previousUrl' => $paginator->previousPageUrl(),
                'nextUrl' => $paginator->nextPageUrl(),
                'pages' => collect(range(1, $paginator->lastPage()))
                    ->map(fn (int $page): array => [
                        'number' => $page,
                        'url' => $paginator->url($page),
                        'current' => $page === $paginator->currentPage(),
                    ])
                    ->all(),
            ],
        ];
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
        $status = $this->stockItemStatus($order, $payment);
        $available = $stockItem->order_item_id === null;

        return [
            'id' => $stockItem->id,
            'secret' => $stockItem->secret,
            'status' => $status,
            'invoice' => $order?->invoice_number,
            'reserved_at' => $order?->created_at?->toDateTimeString(),
            'expires_at' => $order?->expires_at?->toDateTimeString(),
            'paid_at' => $payment?->paid_at?->toDateTimeString(),
            'metadata' => $this->stockItemMetadata($order, $payment, $status),
            'repair_needed' => $stockItem->order_item_id !== null && in_array($status, ['EXPIRED', 'CANCELLED'], true),
            'editable' => $available,
            'update_url' => $available ? route('admin.products.stock-items.update', ['product' => $product, 'stockItem' => $stockItem]) : null,
            'delete_url' => $available ? route('admin.products.stock-items.destroy', ['product' => $product, 'stockItem' => $stockItem]) : null,
            'cancel_release_url' => $status === 'DIPESAN' && $order ? route('admin.orders.cancel-release', $order) : null,
        ];
    }

    private function stockItemStatus($order, $payment): string
    {
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
}
