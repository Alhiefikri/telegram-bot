<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\Money;
use Filament\Widgets\Widget;

class LowStockProductsWidget extends Widget
{
    protected string $view = 'filament.widgets.low-stock-products-widget';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 8,
    ];

    protected function getViewData(): array
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereColumn('stock', '<=', 'reserved_stock')
                    ->orWhere('stock', '<=', 10);
            })
            ->orderBy('stock')
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(function (Product $product): array {
                return [
                    'name' => $product->name,
                    'stock' => (int) $product->stock,
                    'reserved_stock' => (int) $product->reserved_stock,
                    'price' => Money::idr($product->price_amount),
                    'status_label' => $this->statusLabel($product),
                    'tone' => $this->toneForProduct($product),
                    'needs_attention' => (int) $product->stock <= (int) $product->reserved_stock,
                    'url' => ProductResource::getUrl('edit', ['record' => $product]),
                ];
            });

        return [
            'products' => $products,
            'isEmpty' => $products->isEmpty(),
        ];
    }

    private function statusLabel(Product $product): string
    {
        if ((int) $product->stock <= 0) {
            return 'Stok habis';
        }

        if ((int) $product->stock <= (int) $product->reserved_stock || (int) $product->stock <= 10) {
            return 'Perlu restok';
        }

        return 'Aktif';
    }

    private function toneForProduct(Product $product): string
    {
        if ((int) $product->stock <= 0) {
            return 'rose';
        }

        if ((int) $product->stock <= (int) $product->reserved_stock || (int) $product->stock <= 10) {
            return 'amber';
        }

        return 'emerald';
    }
}
