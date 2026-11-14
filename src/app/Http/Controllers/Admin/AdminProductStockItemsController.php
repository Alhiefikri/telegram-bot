<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\AdminDashboardProductsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminProductStockItemsController extends Controller
{
    public function __invoke(Request $request, Product $product, AdminDashboardProductsData $products): JsonResponse
    {
        abort_unless(Gate::allows('adjustStock', $product), 403);

        try {
            $validated = $request->validate([
                'paid_only' => ['sometimes', 'boolean'],
                'paid_cursor' => ['sometimes', 'required', 'string', 'max:4096', 'prohibited_unless:paid_only,1'],
            ]);

            return response()->json($products->stockManagementFor(
                $product,
                $request->boolean('paid_only'),
                $validated['paid_cursor'] ?? null,
            ));
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Navigasi riwayat PAID tidak valid. Muat ulang Kelola akun.',
                'errors' => $exception->errors(),
            ], 422);
        }
    }
}
