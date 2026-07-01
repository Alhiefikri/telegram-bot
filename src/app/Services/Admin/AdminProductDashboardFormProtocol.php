<?php

namespace App\Services\Admin;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Validator;

class AdminProductDashboardFormProtocol
{
    private const PRODUCT_TAB = 'produk';

    private const PRODUCT_CREATE_BAG = 'productCreate';

    private const PRODUCT_UPDATE_BAG = 'productUpdate';

    private const STOCK_ADJUSTMENT_BAG = 'stockAdjustment';

    private const STOCK_ITEM_UPDATE_BAG = 'stockItemUpdate';

    private const CATEGORY_CREATE_BAG = 'categoryCreate';

    public function productCreateValidationErrors(Validator $validator): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($validator, self::PRODUCT_CREATE_BAG)
            ->withInput();
    }

    public function productUpdateValidationErrors(Request $request, Validator $validator, Product $product): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($validator, self::PRODUCT_UPDATE_BAG)
            ->withInput($request->except(['_token', '_method']) + ['product_id' => $product->id]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function stockValidationErrors(Request $request, Product $product, array $errors): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($errors, self::STOCK_ADJUSTMENT_BAG)
            ->withInput($request->only(['stock_items']) + ['stock_product_id' => $product->id]);
    }

    public function stockItemUpdateValidationErrors(Request $request, Validator $validator): RedirectResponse
    {
        return redirect()
            ->back()
            ->withErrors($validator, self::STOCK_ITEM_UPDATE_BAG)
            ->withInput($request->only(['secret']));
    }

    public function productCreated(string $productName): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Produk {$productName} berhasil dibuat. Tambahkan stok saat item sudah siap.");
    }

    public function productUpdated(string $productName): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Produk {$productName} berhasil diperbarui.");
    }

    public function stockAdded(string $productName, int $itemCount): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Stok {$productName} bertambah {$itemCount} item.");
    }

    public function stockItemUpdated(string $productName): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Stok {$productName} diperbarui.");
    }

    public function stockItemDeleted(string $productName): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Satu stok {$productName} dihapus.");
    }

    /**
     * @param  array<string, mixed>  $productsTab
     * @param  array<string, mixed>  $statsTab
     * @param  array<string, mixed>  $navigation
     * @return array<string, mixed>
     */
    public function dashboardState(array $productsTab, array $statsTab, array $navigation, ViewErrorBag $errors): array
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
                'productCategoryId' => old('category_id', $editingProduct['category_id'] ?? ''),
                'productDescription' => old('description', $editingProduct['description'] ?? ''),
                'productFeatures' => old('features', $editingProduct['features'] ?? ''),
                'productSpecifications' => old('specifications', $editingProduct['specifications'] ?? ''),
                'productIsActive' => (bool) old('is_active', $editingProduct['is_active'] ?? true),
                'productFeaturesOpen' => $productCreateErrors->has('features')
                    || $productUpdateErrors->has('features')
                    || trim((string) old('features', $editingProduct['features'] ?? '')) !== '',
                'productSpecificationsOpen' => $productCreateErrors->has('specifications')
                    || $productUpdateErrors->has('specifications')
                    || trim((string) old('specifications', $editingProduct['specifications'] ?? '')) !== '',
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

    private function productTabRedirect(): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['tab' => self::PRODUCT_TAB]);
    }
}
