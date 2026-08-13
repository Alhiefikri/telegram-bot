<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteCategoryRequest;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\CategoryManagementService;
use Illuminate\Http\RedirectResponse;

class AdminCategoryDeleteController extends Controller
{
    public function __invoke(DeleteCategoryRequest $request, Category $category, CategoryManagementService $categories, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        $deleted = $categories->delete($category, $user, $request->ip());

        return $forms->categoryDeleted($deleted['category']->name, $deleted['productCount']);
    }
}
