<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\ProductStockItemManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdminProductStockItemUpdateController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, Product $product, ProductStockItem $stockItem, ProductStockItemManager $stockItemManager, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        Gate::authorize('adjustStock', $product);

        $validator = Validator::make($request->all(), [
            'secret' => ['required', 'string', 'max:5000'],
        ], [
            'secret.required' => 'Isi akun stok tidak boleh kosong.',
            'secret.max' => 'Isi akun stok maksimal 5000 karakter.',
        ]);

        if ($validator->fails()) {
            return $forms->stockItemUpdateValidationErrors($request, $validator);
        }

        $stockItemManager->updateAvailableSecret(
            $product,
            $stockItem,
            trim((string) $validator->validated()['secret']),
            $user,
            $request->ip(),
        );

        return $forms->stockItemUpdated($product->name);
    }
}
