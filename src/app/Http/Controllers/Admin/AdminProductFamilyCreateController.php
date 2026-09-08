<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductFamilyRequest;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductFamilyManagementService;
use App\Services\Admin\ProductImageOptimizer;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AdminProductFamilyCreateController extends Controller
{
    public function __invoke(StoreProductFamilyRequest $request, ProductFamilyManagementService $families, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->attributes->get('adminUser');
        $imagePath = $request->hasFile('image') ? $images->storeFamily($request->file('image')) : null;
        $telegramImagePath = $request->hasFile('telegram_image') ? $images->storeTelegramFamily($request->file('telegram_image')) : null;

        try {
            $family = $families->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sort_order' => (int) $validated['sort_order'],
                'image_path' => $imagePath,
                'telegram_image_path' => $telegramImagePath,
            ], $user, $request->ip());
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                $images->deleteManagedImage($imagePath);
            }
            if ($telegramImagePath !== null) {
                $images->deleteManagedImage($telegramImagePath);
            }

            throw $exception;
        }

        return $forms->productFamilyCreated($family);
    }
}
