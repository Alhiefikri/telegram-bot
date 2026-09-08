<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductFamilyRequest;
use App\Models\ProductFamily;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductFamilyManagementService;
use App\Services\Admin\ProductImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AdminProductFamilyUpdateController extends Controller
{
    public function __invoke(UpdateProductFamilyRequest $request, ProductFamily $productFamily, ProductFamilyManagementService $families, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->attributes->get('adminUser');
        $newImagePath = $request->hasFile('image') ? $images->storeFamily($request->file('image')) : null;
        $newTelegramImagePath = $request->hasFile('telegram_image') ? $images->storeTelegramFamily($request->file('telegram_image')) : null;
        $oldImagePath = $productFamily->image_path;
        $oldTelegramImagePath = $productFamily->telegram_image_path;

        try {
            $family = $families->update($productFamily, [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sort_order' => (int) $validated['sort_order'],
                ...($newImagePath === null ? [] : ['image_path' => $newImagePath]),
                ...($newTelegramImagePath === null ? [] : ['telegram_image_path' => $newTelegramImagePath]),
            ], $user, $request->ip());
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                $images->deleteManagedImage($newImagePath);
            }
            if ($newTelegramImagePath !== null) {
                $images->deleteManagedImage($newTelegramImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null && $oldImagePath !== null && $oldImagePath !== $family->image_path) {
            $images->deleteManagedImage($oldImagePath);
        }
        if ($newTelegramImagePath !== null && $oldTelegramImagePath !== null && $oldTelegramImagePath !== $family->telegram_image_path) {
            $images->deleteManagedImage($oldTelegramImagePath);
        }

        return $forms->productFamilyUpdated($family);
    }
}
