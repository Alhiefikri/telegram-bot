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
    public function __construct(private readonly ProductDescriptionSectionProtocol $descriptionSections, private readonly ProductImageOptimizer $images) {}

    /**
     * @return array<string, mixed>
     */
    public function forProducts(bool $canManageProducts): array
    {
        return [
            'canManage' => $canManageProducts,
            'productStoreUrl' => route('admin.products.store'),
            'products' => $this->latestProducts($canManageProducts),
            'categoryOptions' => $this->categoryOptions(),
        ];
    }

    private function latestProducts(bool $includeManageableStockItems): array
    {
        $products = Product::query()
            ->with('category:id,name')
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'fake_discount_price', 'currency', 'stock', 'reserved_stock', 'image_path', 'is_active', 'created_at'])
            ->withExists('stockItems')
            ->withCount(['stockItems as available_stock_items_count' => fn ($query) => $query->whereNull('order_item_id')])
            ->latest()
            ->limit(5)
            ->get();

        $productIds = $products->pluck('id')->all();
        $stockItemCounts = $includeManageableStockItems
            ? ProductStockItem::query()
                ->whereIn('product_id', $productIds)
                ->selectRaw('product_id, count(*) as aggregate')
                ->groupBy('product_id')
                ->pluck('aggregate', 'product_id')
            : collect();
        if ($includeManageableStockItems) {
            $this->loadOrderedStockItems($products);
        }

        return $products
            ->map(function (Product $product) use ($stockItemCounts, $includeManageableStockItems): array {
                $descriptionSections = $this->descriptionSections->decode($product->description);
                $availableStockItemCount = $product->availableStockItemCount();
                $availableStock = $product->availableStock();
                $stockItems = $includeManageableStockItems
                    ? $product->stockItems
                        ->map(fn (ProductStockItem $stockItem): array => $this->stockItemPayload($stockItem, $product))
                        ->all()
                    : [];
                $stockSummary = $this->stockSummary($stockItems);
                $stockUrl = route('admin.products.stock.store', ['product' => $product]);
                $updateUrl = route('admin.products.update', ['product' => $product]);

                return [
                    'id' => $product->id,
                    'category_id' => $product->category_id,
                    'name' => $product->name,
                    'description' => $descriptionSections['description'],
                    'features' => $descriptionSections['features'],
                    'specifications' => $descriptionSections['specifications'],
                    'category' => $product->category?->name ?? 'Tanpa kategori',
                    'price_amount' => $product->price_amount,
                    'fake_discount_price' => $product->fake_discount_price,
                    'price' => Money::idr($product->price_amount),
                    'stock' => $product->stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => $availableStock,
                    'available_stock_item_count' => $availableStockItemCount,
                    'stock_items_truncated' => (int) ($stockItemCounts[$product->id] ?? 0) > count($stockItems),
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
                        'fakeDiscountPrice' => $product->fake_discount_price,
                        'categoryId' => $product->category_id,
                        'description' => $descriptionSections['description'],
                        'features' => $descriptionSections['features'],
                        'specifications' => $descriptionSections['specifications'],
                        'isActive' => $product->is_active,
                        'imageUrl' => $this->images->publicUrl($product->image_path),
                        'updateUrl' => $updateUrl,
                        'stockUrl' => $stockUrl,
                        'stock' => $product->stock,
                        'availableStockItemCount' => $availableStockItemCount,
                        'stockSummary' => $stockSummary,
                        'stockItemsTruncated' => (int) ($stockItemCounts[$product->id] ?? 0) > count($stockItems),
                        'stockItems' => $stockItems,
                    ],
                ];
            })
            ->all();
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
                ->orderBy("{$stockItemsTable}.id")
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

    private function stockSummary(array $stockItems): array
    {
        return [
            'available' => count(array_filter($stockItems, fn (array $item): bool => $item['status'] === 'TERSEDIA')),
            'reserved' => count(array_filter($stockItems, fn (array $item): bool => $item['status'] === 'DIPESAN')),
            'paid' => count(array_filter($stockItems, fn (array $item): bool => $item['status'] === 'PAID')),
        ];
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
