<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminProductDashboardFormProtocol
{
    private const PRODUCT_TAB = 'produk';

    private const PRODUCT_CREATE_BAG = 'productCreate';

    private const PRODUCT_UPDATE_BAG = 'productUpdate';

    private const STOCK_ADJUSTMENT_BAG = 'stockAdjustment';

    private const STOCK_ITEM_UPDATE_BAG = 'stockItemUpdate';

    private const CATEGORY_CREATE_BAG = 'categoryCreate';

    private const CATEGORY_UPDATE_BAG = 'categoryUpdate';

    private const CATEGORY_DELETE_BAG = 'categoryDelete';

    private const PRODUCT_FAMILY_CREATE_BAG = 'productFamilyCreate';

    private const PRODUCT_FAMILY_UPDATE_BAG = 'productFamilyUpdate';

    private const PRODUCT_FAMILY_DELETE_BAG = 'productFamilyDelete';

    private const PRODUCT_FAMILY_MEMBERSHIP_BAG = 'productFamilyMembership';

    public function __construct(private readonly AdminDashboardRedirector $redirector) {}

    public function productCreateValidationErrors(Validator $validator): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($validator, self::PRODUCT_CREATE_BAG)
            ->withInput();
    }

    public function categoryCreateValidationErrors(Request $request, Validator $validator): RedirectResponse
    {
        return $this->categoryManagementRedirect('create')
            ->withErrors($validator, self::CATEGORY_CREATE_BAG)
            ->withInput($request->only(['category_name', 'category_description']));
    }

    public function categoryUpdateValidationErrors(Request $request, Validator $validator, Category $category): RedirectResponse
    {
        return $this->categoryManagementRedirect('edit', $category->id)
            ->withErrors($validator->errors()->messages(), self::CATEGORY_UPDATE_BAG)
            ->withInput([
                'category_id' => $category->id,
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'is_active' => $request->input('is_active'),
                'sort_order' => $request->input('sort_order'),
            ]);
    }

    public function categoryDeleteValidationErrors(Validator $validator): RedirectResponse
    {
        return $this->categoryManagementRedirect('create')
            ->withErrors($validator, self::CATEGORY_DELETE_BAG);
    }

    public function productUpdateValidationErrors(Request $request, Validator $validator, Product $product): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($validator->errors()->messages(), self::PRODUCT_UPDATE_BAG)
            ->withInput([
                'name' => $request->input('name'),
                'icon' => $request->input('icon'),
                'price_amount' => $request->input('price_amount'),
                'usdt_price_amount' => $request->input('usdt_price_amount'),
                'quantity_price_tiers_present' => $request->input('quantity_price_tiers_present'),
                'quantity_price_tiers' => $request->input('quantity_price_tiers', []),
                'description' => $request->input('description'),
                'product_type' => $request->input('product_type'),
                'access_duration' => $request->input('access_duration'),
                'region' => $request->input('region'),
                'account_type' => $request->input('account_type'),
                'device_limit' => $request->input('device_limit'),
                'included_items' => $request->input('included_items'),
                'limitations' => $request->input('limitations'),
                'fulfillment_expectation' => $request->input('fulfillment_expectation'),
                'warranty_policy' => $request->input('warranty_policy'),
                'support_policy' => $request->input('support_policy'),
                'featured_rank' => $request->input('featured_rank'),
                'delivery_note' => $request->input('delivery_note'),
                'usage_guide_title' => $request->input('usage_guide_title'),
                'usage_guide_url' => $request->input('usage_guide_url'),
                'is_active' => $request->input('is_active'),
                'product_id' => $product->id,
            ]);
    }

    public function productUpdateBusinessValidationError(Request $request, Product $product, string $field, string $message): RedirectResponse
    {
        return $this->redirector->product($product)
            ->withErrors([$field => $message], self::PRODUCT_UPDATE_BAG)
            ->withInput($request->except(['_token', '_method', 'image']));
    }

    public function productFamilyCreateValidationErrors(Request $request, Validator $validator): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('create')
            ->withErrors($validator, self::PRODUCT_FAMILY_CREATE_BAG)
            ->withInput($request->only(['name', 'description', 'sort_order']));
    }

    public function productFamilyUpdateValidationErrors(Request $request, Validator $validator, ProductFamily $family): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('edit', $family->id)
            ->withErrors($validator, self::PRODUCT_FAMILY_UPDATE_BAG)
            ->withInput([
                'family_id' => $family->id,
                'name' => $request->input('name'),
                'slug' => $request->input('slug'),
                'description' => $request->input('description'),
                'sort_order' => $request->input('sort_order'),
            ]);
    }

    public function productFamilyUpdateBusinessValidationError(Request $request, ProductFamily $family, string $field, string $message): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('edit', $family->id)
            ->withErrors([$field => $message], self::PRODUCT_FAMILY_UPDATE_BAG)
            ->withInput([
                'family_id' => $family->id,
                'name' => $request->input('name'),
                'slug' => $request->input('slug'),
                'description' => $request->input('description'),
                'sort_order' => $request->input('sort_order'),
            ]);
    }

    public function productFamilyDeleteValidationErrors(Validator $validator): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('create')
            ->withErrors($validator, self::PRODUCT_FAMILY_DELETE_BAG);
    }

    public function productFamilyMembershipValidationErrors(Request $request, Validator $validator, Product $product): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($validator, self::PRODUCT_FAMILY_MEMBERSHIP_BAG)
            ->withInput([
                'family_product_id' => $product->id,
                'product_family_id' => $request->input('product_family_id', $request->input('family_id')),
                'family_sort_order' => $request->input('family_sort_order', $request->input('sort_order', 0)),
                'family_option_label' => $request->input('family_option_label', $request->input('option_label')),
            ]);
    }

    public function productFamilyMembershipBusinessValidationError(Request $request, Product $product, string $message): RedirectResponse
    {
        return $this->redirector->product($product)
            ->withErrors(['product_family_id' => $message], self::PRODUCT_FAMILY_MEMBERSHIP_BAG)
            ->withInput([
                'family_product_id' => $product->id,
                'product_family_id' => $request->input('product_family_id', $request->input('family_id')),
                'family_sort_order' => $request->input('family_sort_order', $request->input('sort_order', 0)),
                'family_option_label' => $request->input('family_option_label', $request->input('option_label')),
            ]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function stockValidationErrors(Request $request, Product $product, array $errors): RedirectResponse
    {
        return $this->productTabRedirect()
            ->withErrors($errors, self::STOCK_ADJUSTMENT_BAG)
            ->withInput([
                'stock_product_id' => $product->id,
                'stock_format' => $request->input('stock_format'),
                'operation_key' => $request->input('operation_key'),
            ]);
    }

    public function stockItemUpdateValidationErrors(Request $request, Validator|array $validator, Product $product): RedirectResponse
    {
        $stockItem = $request->route('stockItem');

        return $this->productTabRedirect()
            ->withErrors($validator, self::STOCK_ITEM_UPDATE_BAG)
            ->withInput([
                'stock_item_product_id' => $product->id,
                'stock_item_id' => $stockItem instanceof ProductStockItem ? $stockItem->id : null,
            ]);
    }

    public function productCreated(Product $product): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Produk {$product->name} berhasil dibuat. Tambahkan stok saat item sudah siap.");
    }

    public function productUpdated(Product $product): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Produk {$product->name} berhasil diperbarui.");
    }

    public function productFamilyCreated(ProductFamily $family): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('edit', $family->id)
            ->with('status', "Product Family {$family->name} berhasil dibuat.");
    }

    public function productFamilyUpdated(ProductFamily $family): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('edit', $family->id)
            ->with('status', "Product Family {$family->name} berhasil diperbarui.");
    }

    public function productFamilyDeleted(string $familyName, int $productCount): RedirectResponse
    {
        return $this->productFamilyManagementRedirect('create')
            ->with('status', "Product Family {$familyName} dihapus. {$productCount} Product tidak lagi tergabung dalam family; state Product tetap dipertahankan.");
    }

    public function productFamilyMembershipUpdated(Product $product): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Grouping Product {$product->name} berhasil diperbarui.");
    }

    public function stockAdded(Product $product, int $itemCount): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Stok {$product->name} bertambah {$itemCount} item.");
    }

    public function stockItemUpdated(Product $product): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Stok {$product->name} diperbarui.");
    }

    public function stockItemDeleted(Product $product): RedirectResponse
    {
        return $this->redirector->product($product)
            ->with('status', "Satu stok {$product->name} dihapus.");
    }

    public function categoryCreated(string $categoryName): RedirectResponse
    {
        return $this->categoryManagementRedirect('create')
            ->with('status', "Kategori {$categoryName} berhasil dibuat.");
    }

    private function productFamilyManagementRedirect(?string $form = null, ?int $familyId = null): RedirectResponse
    {
        return $this->redirector->productFamilyManagement($form, $familyId);
    }

    public function categoryUpdated(Category $category): RedirectResponse
    {
        return $this->categoryManagementRedirect('edit', $category->id)
            ->with('status', "Kategori {$category->name} berhasil diperbarui.");
    }

    public function categoryDeleted(string $categoryName, int $productCount): RedirectResponse
    {
        return $this->categoryManagementRedirect('create')
            ->with('status', "Kategori {$categoryName} dihapus. {$productCount} produk menjadi Tanpa kategori; status aktif, stok, dan reservasi tetap dipertahankan.");
    }

    private function productTabRedirect(): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['tab' => self::PRODUCT_TAB]);
    }

    private function categoryManagementRedirect(?string $form = null, ?int $categoryId = null): RedirectResponse
    {
        return $this->redirector->categoryManagement($form, $categoryId);
    }
}
