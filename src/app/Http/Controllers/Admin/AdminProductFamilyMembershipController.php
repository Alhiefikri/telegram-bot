<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductFamilyMembershipRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductFamilyManagementService;
use Illuminate\Http\RedirectResponse;

class AdminProductFamilyMembershipController extends Controller
{
    public function __invoke(UpdateProductFamilyMembershipRequest $request, Product $product, ProductFamilyManagementService $families, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $updated = $families->updateMembership(
                $product,
                isset($validated['product_family_id']) && $validated['product_family_id'] !== null
                    ? (int) $validated['product_family_id']
                    : null,
                (int) ($validated['family_sort_order'] ?? 0),
                $validated['family_option_label'] ?? null,
                $request->attributes->get('adminUser'),
                $request->ip(),
            );
        } catch (ProductFamilyCategoryMismatch $exception) {
            return $forms->productFamilyMembershipBusinessValidationError($request, $product, $exception->getMessage());
        }

        return $forms->productFamilyMembershipUpdated($updated, $request);
    }
}
