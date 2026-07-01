<?php

namespace App\Services\Admin;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function categoryCreateValidationErrors(Request $request, Validator $validator): RedirectResponse
    {
        return redirect()
            ->back()
            ->withErrors($validator, self::CATEGORY_CREATE_BAG)
            ->withInput($request->only(['category_name', 'category_description']));
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

    public function categoryCreated(string $categoryName): RedirectResponse
    {
        return $this->productTabRedirect()
            ->with('status', "Kategori {$categoryName} berhasil dibuat.");
    }

    private function productTabRedirect(): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['tab' => self::PRODUCT_TAB]);
    }
}
