<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardProductsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminProductCardsController extends Controller
{
    public function __invoke(Request $request, AdminDashboardProductsData $products): JsonResponse
    {
        $validator = validator($request->all(), [
            'ids' => ['required', 'array', 'min:1', 'max:12'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();
        $canManageProducts = (bool) $request->attributes->get('adminUser')?->role?->canMutateProducts();
        $cards = [];
        foreach ($products->cardsFor(array_map('intval', $validated['ids'])) as $product) {
            $cards[] = view('admin.dashboard.product-card', compact('product', 'canManageProducts'))->render();
        }

        return response()->json(['html' => implode('', $cards)]);
    }
}
