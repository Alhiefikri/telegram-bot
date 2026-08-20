<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CredentialFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductStockItemRequest;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\ProductStockItemManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminProductStockItemUpdateController extends Controller
{
    public function __invoke(UpdateProductStockItemRequest $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        try {
            if (($stockItem->credential_format ?? CredentialFormat::Legacy) === CredentialFormat::StructuredAccount) {
                $stockItemManager->updateAvailableStructured(
                    $product,
                    $stockItem,
                    $request->validated(),
                    $user,
                    $request->ip(),
                );
            } else {
                $stockItemManager->updateAvailableSecret(
                    $product,
                    $stockItem,
                    trim((string) $request->validated('secret')),
                    $user,
                    $request->ip(),
                );
            }
        } catch (ValidationException $exception) {
            return $forms->stockItemUpdateValidationErrors($request, $exception->errors(), $product);
        }

        return $forms->stockItemUpdated($product->fresh());
    }
}
