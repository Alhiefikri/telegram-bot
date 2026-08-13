<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\CategoryManagementService;
use Illuminate\Http\RedirectResponse;

class AdminCategoryUpdateController extends Controller
{
    public function __invoke(UpdateCategoryRequest $request, Category $category, CategoryManagementService $categories, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();
        $updated = $categories->update($category, [
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => (int) $validated['sort_order'],
            'is_active' => (bool) $validated['is_active'],
        ], $user, $request->ip());

        return $forms->categoryUpdated($updated);
    }
}
