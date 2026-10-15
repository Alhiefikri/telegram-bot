<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductFamilyMembershipRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Admin\ProductFamilyManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminProductFamilyMembershipController extends Controller
{
    public function __invoke(UpdateProductFamilyMembershipRequest $request, Product $product, ProductFamilyManagementService $families, AdminProductDashboardFormProtocol $forms): RedirectResponse|JsonResponse
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
                $validated['workspace_version'] ?? null,
            );
        } catch (ProductFamilyCategoryMismatch $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'errors' => ['product_family_id' => [$exception->getMessage()]]], 422);
            }

            return $forms->productFamilyMembershipBusinessValidationError($request, $product, $exception->getMessage());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Periksa kembali isian yang ditandai.',
                    'errors' => $exception->errors(),
                ], 422)->header('Cache-Control', 'no-store, private');
            }

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Grouping produk diperbarui.', 'workspace' => app(AdminProductWorkspaceData::class)->for($updated)])->header('Cache-Control', 'no-store, private');
        }

        return $forms->productFamilyMembershipUpdated($updated);
    }
}
