<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteProductFamilyRequest;
use App\Models\ProductFamily;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductFamilyManagementService;
use App\Services\Admin\ProductImageOptimizer;
use Illuminate\Http\RedirectResponse;

class AdminProductFamilyDeleteController extends Controller
{
    public function __invoke(DeleteProductFamilyRequest $request, ProductFamily $productFamily, ProductFamilyManagementService $families, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $deleted = $families->delete(
            $productFamily,
            $request->attributes->get('adminUser'),
            $request->ip(),
        );

        $images->deleteManagedImage($deleted['imagePath']);

        return $forms->productFamilyDeleted($deleted['family']->name, $deleted['productCount']);
    }
}
