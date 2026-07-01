<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCategoryCreateController extends Controller
{
    public function __invoke(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        Gate::authorize('create', Category::class);

        $validator = Validator::make($request->all(), [
            'category_name' => ['required', 'string', 'max:120'],
            'category_description' => ['nullable', 'string', 'max:500'],
        ], [
            'category_name.required' => 'Nama kategori wajib diisi.',
            'category_name.max' => 'Nama kategori maksimal 120 karakter.',
            'category_description.max' => 'Deskripsi kategori maksimal 500 karakter.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->back()
                ->withErrors($validator, 'categoryCreate')
                ->withInput($request->only(['category_name', 'category_description']));
        }

        $validated = $validator->validated();
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

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Kategori {$category->name} berhasil dibuat.");
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
