<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Models\Category;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class AdminCategoryCreateController extends Controller
{
    public function __invoke(StoreCategoryRequest $request, AuditLogger $auditLogger, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $category = Category::create([
            'name' => trim((string) $validated['category_name']),
            'slug' => $this->uniqueSlug((string) $validated['category_name']),
            'description' => filled($validated['category_description'] ?? null) ? trim((string) $validated['category_description']) : null,
            'banner_image_path' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $auditLogger->log(
            'category.created',
            $category,
            null,
            $category->only(['name', 'slug', 'description', 'sort_order', 'is_active']),
            $user,
            $request->ip(),
        );

        return $forms->categoryCreated($category->name);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(trim($name)) ?: 'kategori';
        $slug = $base;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
