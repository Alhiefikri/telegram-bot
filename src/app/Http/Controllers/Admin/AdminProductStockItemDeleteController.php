<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\ProductStockItemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminProductStockItemDeleteController extends Controller
{
    public function __invoke(Request $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager, AdminProductDashboardFormProtocol $forms): RedirectResponse|JsonResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('adjustStock', $product);

        $stockItemManager->deleteAvailable($product, $stockItem, $user, $request->ip());

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Satu stok dihapus.', 'workspace' => app(AdminProductWorkspaceData::class)->for($product)])->header('Cache-Control', 'no-store, private');
        }

        return $forms->stockItemDeleted($product->fresh());
    }
}
