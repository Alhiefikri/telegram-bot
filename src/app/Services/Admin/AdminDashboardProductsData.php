<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Support\Money;

final class AdminDashboardProductsData
{
    public function __construct(private readonly ProductDescriptionSectionProtocol $descriptionSections) {}

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
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'is_active', 'created_at'])
            ->latest()
            ->limit(5)
            ->get();

        $productIds = $products->pluck('id')->all();
        $availableStockCounts = $includeManageableStockItems
            ? ProductStockItem::query()
                ->whereIn('product_id', $productIds)
                ->whereNull('order_item_id')
                ->selectRaw('product_id, count(*) as aggregate')
                ->groupBy('product_id')
                ->pluck('aggregate', 'product_id')
            : collect();

        return $products
            ->map(function (Product $product) use ($availableStockCounts, $includeManageableStockItems): array {
                $descriptionSections = $this->descriptionSections->decode($product->description);
                $availableStockItemCount = (int) ($availableStockCounts[$product->id] ?? 0);
                $stockItems = $includeManageableStockItems
                    ? ProductStockItem::query()
                        ->where('product_id', $product->id)
                        ->whereNull('order_item_id')
                        ->oldest('id')
                        ->limit(25)
                        ->get()
                        ->map(fn (ProductStockItem $stockItem): array => [
                            'id' => $stockItem->id,
                            'secret' => $stockItem->secret,
                            'update_url' => route('admin.products.stock-items.update', ['product' => $product, 'stockItem' => $stockItem]),
                            'delete_url' => route('admin.products.stock-items.destroy', ['product' => $product, 'stockItem' => $stockItem]),
                        ])
                        ->all()
                    : [];
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
                    'price' => Money::idr($product->price_amount),
                    'stock' => $product->stock,
                    'reserved_stock' => $product->reserved_stock,
                    'available_stock' => max(0, $product->stock - $product->reserved_stock),
                    'available_stock_item_count' => $availableStockItemCount,
                    'stock_items_truncated' => $availableStockItemCount > count($stockItems),
                    'stock_items' => $stockItems,
                    'is_active' => $product->is_active,
                    'status_label' => $product->is_active ? 'Aktif' : 'Pause',
                    'status_badge' => $this->productStatusBadge($product->is_active),
                    'visibility_label' => $product->is_active ? '● Tampil di toko' : '○ Tidak tampil',
                    'visibility_color' => $product->is_active ? '#7BD10A' : '#ef4444',
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
                        'categoryId' => $product->category_id,
                        'description' => $descriptionSections['description'],
                        'features' => $descriptionSections['features'],
                        'specifications' => $descriptionSections['specifications'],
                        'isActive' => $product->is_active,
                        'updateUrl' => $updateUrl,
                        'stockUrl' => $stockUrl,
                        'stock' => $product->stock,
                        'availableStockItemCount' => $availableStockItemCount,
                        'stockItemsTruncated' => $availableStockItemCount > count($stockItems),
                        'stockItems' => $stockItems,
                    ],
                ];
            })
            ->all();
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
