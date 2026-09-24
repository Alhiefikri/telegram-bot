<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\AdminDashboardProductsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AdminProductStockItemsController extends Controller
{
    public function __invoke(Product $product, AdminDashboardProductsData $products): JsonResponse
    {
        abort_unless(Gate::allows('adjustStock', $product), 403);

        return response()->json($products->stockManagementFor($product));
    }
}
