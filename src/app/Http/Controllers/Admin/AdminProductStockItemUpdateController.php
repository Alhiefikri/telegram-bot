<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductStockItemRequest;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\ProductStockItemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminProductStockItemUpdateController extends Controller
{
    public function __invoke(UpdateProductStockItemRequest $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager, AdminProductDashboardFormProtocol $forms): RedirectResponse|JsonResponse
    {
        $user = $request->attributes->get('adminUser');

        try {
            if ($stockItem->safeCredentialFormat()?->value === 'structured_account') {
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
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Stok tidak dapat diperbarui.', 'errors' => $exception->errors()], 422);
            }

            return $forms->stockItemUpdateValidationErrors($request, $exception->errors(), $product);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Stok diperbarui.', 'workspace' => app(AdminProductWorkspaceData::class)->for($product)])->header('Cache-Control', 'no-store, private');
        }

        return $forms->stockItemUpdated($product->fresh());
    }
}
