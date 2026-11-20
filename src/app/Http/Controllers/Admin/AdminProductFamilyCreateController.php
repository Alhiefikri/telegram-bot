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
        $sidebarIconPath = null;

        try {
            $sidebarIconPath = $request->hasFile('sidebar_icon') ? $images->storeFamilySidebarIcon($request->file('sidebar_icon')) : null;
            $family = $families->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'icon' => $validated['icon'] ?? null,
                'sort_order' => (int) $validated['sort_order'],
                'image_path' => $imagePath,
                'sidebar_icon_path' => $sidebarIconPath,
                'telegram_image_path' => $telegramImagePath,
            ], $user, $request->ip());
        } catch (Throwable $exception) {
            $images->deleteManagedImage($sidebarIconPath);
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
