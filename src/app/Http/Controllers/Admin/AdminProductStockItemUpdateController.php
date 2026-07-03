<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductStockItemRequest;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\ProductStockItemManager;
use Illuminate\Http\RedirectResponse;

class AdminProductStockItemUpdateController extends Controller
{
    public function __invoke(UpdateProductStockItemRequest $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $stockItemManager->updateAvailableSecret(
            $product,
            $stockItem,
            trim((string) $request->validated('secret')),
            $user,
            $request->ip(),
        );

        return $forms->stockItemUpdated($product->name);
    }
}
