<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\ProductStockItemManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminProductStockItemDeleteController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        Gate::authorize('adjustStock', $product);

        $stockItemManager->deleteAvailable($product, $stockItem, $user, $request->ip());

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Satu stok {$product->name} dihapus.");
    }
}
