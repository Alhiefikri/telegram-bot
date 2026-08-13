<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\CategoryManagementService;
use Illuminate\Http\RedirectResponse;

class AdminCategoryCreateController extends Controller
{
    public function __invoke(StoreCategoryRequest $request, CategoryManagementService $categories, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $category = $categories->create(
            (string) $validated['category_name'],
            $validated['category_description'] ?? null,
            $user,
            $request->ip(),
        );

        return $forms->categoryCreated($category->name);
    }
}
