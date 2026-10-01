<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductStockItemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminProductStockItemsBulkDeleteController extends Controller
{
    public function __invoke(Request $request, Product $product, ProductStockItemManager $manager): JsonResponse
    {
        Gate::authorize('adjustStock', $product);

        try {
            $validated = $request->validate([
                'stock_item_ids' => ['required', 'array', 'min:1', 'max:100'],
                'stock_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ]);
            $ids = array_map(intval(...), $validated['stock_item_ids']);
            $manager->deleteAvailableBatch($product, $ids, $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException) {
            return response()->json([
                'message' => 'Pilihan stok tidak valid atau sudah berubah. Tidak ada stok dihapus.',
            ], 422)->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['deleted_count' => count($ids)])
            ->header('Cache-Control', 'no-store, private');
    }
}
