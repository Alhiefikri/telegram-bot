<?php

namespace App\Services\Admin;

use Illuminate\Support\ViewErrorBag;

class AdminProductDashboardState
{
    private const PRODUCT_TAB = 'produk';

    private const PRODUCT_CREATE_BAG = 'productCreate';

    private const PRODUCT_UPDATE_BAG = 'productUpdate';

    private const STOCK_ADJUSTMENT_BAG = 'stockAdjustment';

    private const STOCK_ITEM_UPDATE_BAG = 'stockItemUpdate';

    private const CATEGORY_CREATE_BAG = 'categoryCreate';

    /**
     * @param  array<string, mixed>  $productsTab
     * @param  array<string, mixed>  $statsTab
     * @param  array<string, mixed>  $navigation
     * @return array<string, mixed>
     */
    public function build(array $productsTab, array $statsTab, array $navigation, ViewErrorBag $errors): array
    {
        $products = collect($productsTab['products']);
        $stockModalProduct = $products->firstWhere('id', (int) old('stock_product_id'));
        $editingProduct = $products->firstWhere('id', (int) old('product_id'));
        $productStoreUrl = $productsTab['productStoreUrl'];

        $productCreateErrors = $errors->getBag(self::PRODUCT_CREATE_BAG);
        $productUpdateErrors = $errors->getBag(self::PRODUCT_UPDATE_BAG);
        $stockAdjustmentErrors = $errors->getBag(self::STOCK_ADJUSTMENT_BAG);
        $categoryCreateErrors = $errors->getBag(self::CATEGORY_CREATE_BAG);
        $stockItemUpdateErrors = $errors->getBag(self::STOCK_ITEM_UPDATE_BAG);

        $hasProductErrors = $productCreateErrors->count() > 0 || $productUpdateErrors->count() > 0;
        $hasProductUpdateErrors = $productUpdateErrors->count() > 0;
        $hasProductTabErrors = $hasProductErrors
            || $stockAdjustmentErrors->count() > 0
            || $categoryCreateErrors->count() > 0
            || $stockItemUpdateErrors->count() > 0;

        return [
            'activeTab' => $hasProductTabErrors ? self::PRODUCT_TAB : $navigation['activeTab'],
            'storeLink' => $statsTab['storeLink']['url'],
            'productStoreUrl' => $productStoreUrl,
            'productModalOpen' => $hasProductErrors,
            'productForm' => [
                'productModalMode' => $hasProductUpdateErrors ? 'edit' : 'create',
                'productFormAction' => $hasProductUpdateErrors ? ($editingProduct['update_url'] ?? $productStoreUrl) : $productStoreUrl,
                'productFormMethod' => $hasProductUpdateErrors ? 'PATCH' : '',
                'productId' => old('product_id', $editingProduct['id'] ?? null),
                'productName' => old('name', $editingProduct['name'] ?? ''),
                'productPrice' => old('price_amount', $editingProduct['price_amount'] ?? ''),
                'productUsdtPrice' => old('usdt_price_amount', $editingProduct['usdt_price_amount'] ?? ''),
                'productCategoryId' => old('category_id', $editingProduct['category_id'] ?? ''),
                'productDescription' => old('description', $editingProduct['description'] ?? ''),
                'productDeliveryNote' => old('delivery_note', $editingProduct['delivery_note'] ?? ''),
                'productIsActive' => (bool) old('is_active', $editingProduct['is_active'] ?? true),
                'productImageUrl' => $editingProduct['image_url'] ?? '',
                'productDeliveryNoteOpen' => $productCreateErrors->has('delivery_note')
                    || $productUpdateErrors->has('delivery_note')
                    || trim((string) old('delivery_note', $editingProduct['delivery_note'] ?? '')) !== '',
            ],
            'stockModalOpen' => $stockAdjustmentErrors->count() > 0,
            'stockForm' => [
                'stockFormAction' => $stockModalProduct['stock_url'] ?? '',
                'stockProductId' => $stockModalProduct['id'] ?? null,
                'stockProductName' => $stockModalProduct['name'] ?? '',
                'stockProductStock' => $stockModalProduct['stock'] ?? 0,
                'stockItems' => old('stock_items', ''),
            ],
        ];
    }
}
