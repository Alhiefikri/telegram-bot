<?php

namespace App\Services\Admin;

use App\Services\ProductStockIntake;
use Illuminate\Support\ViewErrorBag;

class AdminProductDashboardState
{
    public function __construct(private readonly ProductStockIntake $stockIntake) {}

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

    /**
     * @param  array<string, mixed>  $productsTab
     * @param  array<string, mixed>  $statsTab
     * @param  array<string, mixed>  $navigation
     * @return array<string, mixed>
     */
    public function build(array $productsTab, array $statsTab, array $navigation, ViewErrorBag $errors, ?string $categoryFormMode = null, ?int $categoryId = null, ?string $familyFormMode = null, ?int $familyId = null): array
    {
        $products = collect($productsTab['products']);
        $categoryManagement = $productsTab['categoryManagement'] ?? ['createUrl' => '', 'categories' => []];
        $familyManagement = $productsTab['familyManagement'] ?? ['createUrl' => '', 'families' => []];
        $categories = collect($categoryManagement['categories']);
        $families = collect($familyManagement['families']);
        $stockModalProduct = $products->firstWhere('id', (int) old('stock_product_id'));
        $stockItemUpdateProduct = $products->firstWhere('id', (int) old('stock_item_product_id'));
        $editingProduct = $products->firstWhere('id', (int) old('product_id'));
        $editingCategory = $categories->firstWhere('id', $categoryId ?? (int) old('category_id'));
        $editingFamily = $families->firstWhere('id', $familyId ?? (int) old('family_id'));
        $productStoreUrl = $productsTab['productStoreUrl'];

        $productCreateErrors = $errors->getBag(self::PRODUCT_CREATE_BAG);
        $productUpdateErrors = $errors->getBag(self::PRODUCT_UPDATE_BAG);
        $stockAdjustmentErrors = $errors->getBag(self::STOCK_ADJUSTMENT_BAG);
        $categoryCreateErrors = $errors->getBag(self::CATEGORY_CREATE_BAG);
        $categoryUpdateErrors = $errors->getBag(self::CATEGORY_UPDATE_BAG);
        $categoryDeleteErrors = $errors->getBag(self::CATEGORY_DELETE_BAG);
        $stockItemUpdateErrors = $errors->getBag(self::STOCK_ITEM_UPDATE_BAG);
        $familyCreateErrors = $errors->getBag(self::PRODUCT_FAMILY_CREATE_BAG);
        $familyUpdateErrors = $errors->getBag(self::PRODUCT_FAMILY_UPDATE_BAG);
        $familyDeleteErrors = $errors->getBag(self::PRODUCT_FAMILY_DELETE_BAG);
        $familyMembershipErrors = $errors->getBag(self::PRODUCT_FAMILY_MEMBERSHIP_BAG);

        $hasProductErrors = $productCreateErrors->count() > 0 || $productUpdateErrors->count() > 0;
        $hasProductUpdateErrors = $productUpdateErrors->count() > 0;
        $hasCategoryUpdateErrors = $categoryUpdateErrors->count() > 0;
        $hasFamilyErrors = $familyCreateErrors->count() > 0 || $familyUpdateErrors->count() > 0 || $familyDeleteErrors->count() > 0;
        $isCategoryEdit = $categoryFormMode === 'edit' || $hasCategoryUpdateErrors;
        $isFamilyEdit = $familyFormMode === 'edit' || $familyUpdateErrors->count() > 0;
        $hasCategoryFormState = $categoryFormMode !== null;
        $hasFamilyFormState = $familyFormMode !== null;
        $hasProductTabErrors = $hasProductErrors
            || $stockAdjustmentErrors->count() > 0
            || $categoryCreateErrors->count() > 0
            || $categoryUpdateErrors->count() > 0
            || $categoryDeleteErrors->count() > 0
            || $stockItemUpdateErrors->count() > 0
            || $hasFamilyErrors
            || $familyMembershipErrors->count() > 0;

        return [
            'activeTab' => $hasProductTabErrors ? self::PRODUCT_TAB : $navigation['activeTab'],
            'storeLink' => $statsTab['storeLink']['url'],
            'productStoreUrl' => $productStoreUrl,
            'categoryCreateUrl' => $categoryManagement['createUrl'],
            'productModalOpen' => $hasProductErrors,
            'categoryModalOpen' => $hasCategoryFormState || $categoryCreateErrors->count() > 0 || $hasCategoryUpdateErrors || $categoryDeleteErrors->count() > 0,
            'categoryForm' => [
                'categoryFormMode' => $isCategoryEdit ? 'edit' : 'create',
                'categoryFormAction' => $isCategoryEdit ? ($editingCategory['update_url'] ?? $categoryManagement['createUrl']) : $categoryManagement['createUrl'],
                'categoryFormMethod' => $isCategoryEdit ? 'PATCH' : '',
                'categoryId' => old('category_id', $editingCategory['id'] ?? null),
                'categoryName' => old($isCategoryEdit ? 'name' : 'category_name', $editingCategory['name'] ?? ''),
                'categoryDescription' => old($isCategoryEdit ? 'description' : 'category_description', $editingCategory['description'] ?? ''),
                'categoryIsActive' => $this->booleanInput(old('is_active', $editingCategory['is_active'] ?? true)),
                'categorySortOrder' => old('sort_order', $editingCategory['sort_order'] ?? 0),
            ],
            'familyCreateUrl' => $familyManagement['createUrl'],
            'familyModalOpen' => $hasFamilyFormState || $hasFamilyErrors,
            'familyForm' => [
                'familyFormMode' => $isFamilyEdit ? 'edit' : 'create',
                'familyFormAction' => $isFamilyEdit ? ($editingFamily['update_url'] ?? $familyManagement['createUrl']) : $familyManagement['createUrl'],
                'familyFormMethod' => $isFamilyEdit ? 'PATCH' : '',
                'familyId' => old('family_id', $editingFamily['id'] ?? null),
                'familySlug' => old('slug', $editingFamily['slug'] ?? ''),
                'familyName' => old('name', $editingFamily['name'] ?? ''),
                'familyDescription' => old('description', $editingFamily['description'] ?? ''),
                'familySortOrder' => old('sort_order', $editingFamily['sort_order'] ?? 0),
                'familyImageUrl' => $editingFamily['image_url'] ?? '',
                'telegramFamilyImageUrl' => $editingFamily['telegram_image_url'] ?? '',
            ],
            'familyMembershipProductId' => old('family_product_id'),
            'familyMembershipErrors' => $familyMembershipErrors->toArray(),
            'productForm' => [
                'productModalMode' => $hasProductUpdateErrors ? 'edit' : 'create',
                'productFormAction' => $hasProductUpdateErrors ? ($editingProduct['update_url'] ?? $productStoreUrl) : $productStoreUrl,
                'productFormMethod' => $hasProductUpdateErrors ? 'PATCH' : '',
                'productId' => old('product_id', $editingProduct['id'] ?? null),
                'productName' => old('name', $editingProduct['name'] ?? ''),
                'productIcon' => old('icon', $editingProduct['icon'] ?? ''),
                'productPrice' => old('price_amount', $editingProduct['price_amount'] ?? ''),
                'productUsdtPrice' => old('usdt_price_amount', $editingProduct['usdt_price_amount'] ?? ''),
                'productCategoryId' => old('category_id', $editingProduct['category_id'] ?? ''),
                'productDescription' => old('description', $editingProduct['description'] ?? ''),
                'productDeliveryNote' => old('delivery_note', $editingProduct['delivery_note'] ?? ''),
                'productUsageGuideTitle' => old('usage_guide_title', $editingProduct['usage_guide_title'] ?? ''),
                'productUsageGuideUrl' => old('usage_guide_url', $editingProduct['usage_guide_url'] ?? ''),
            ],
            'stockModalOpen' => $stockAdjustmentErrors->count() > 0,
            'structuredStockInputEnabled' => $this->stockIntake->structuredInputEnabled(),
            'manageStockModalOpen' => $stockItemUpdateErrors->count() > 0 && $stockItemUpdateProduct !== null,
            'manageStockPayload' => $stockItemUpdateProduct['edit_payload'] ?? null,
            'manageStockEditingItemId' => old('stock_item_id') !== null ? (int) old('stock_item_id') : null,
            'manageStockErrors' => $stockItemUpdateErrors->toArray(),
            'stockForm' => [
                'stockFormAction' => $stockModalProduct['stock_url'] ?? '',
                'stockAccountsFormAction' => $stockModalProduct['stock_accounts_url'] ?? '',
                'stockAccountsReviewAction' => $stockModalProduct['stock_accounts_review_url'] ?? '',
                'stockReturnUrl' => isset($stockModalProduct['id'])
                    ? route('admin.dashboard', ['tab' => self::PRODUCT_TAB]).'#product-'.$stockModalProduct['id']
                    : '',
                'stockProductId' => $stockModalProduct['id'] ?? null,
                'stockProductName' => $stockModalProduct['name'] ?? '',
                'stockProductStock' => $stockModalProduct['stock'] ?? 0,
                'stockProductDeliveryNote' => $stockModalProduct['delivery_note'] ?? null,
                'stockItems' => old('stock_items', ''),
                'stockFormat' => old('stock_format', 'legacy'),
                'stockOperationKey' => old('operation_key', ''),
            ],
        ];
    }

    private function booleanInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
