<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogBrandIcon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminCatalogBrandIconController extends Controller
{
    public function index(): View
    {
        return view('admin.brand-icons.index', [
            'brands' => CatalogBrandIcon::query()->catalogOrder()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $brand = CatalogBrandIcon::query()->create([
            ...$data,
            'slug' => $this->uniqueSlug($data['label']),
            'custom_emoji_id' => null,
        ]);

        return back()->with('status', sprintf(
            'Brand %s ditambahkan. Ambil emoji-nya lewat menu 🎨 Ikon Brand di bot.',
            $brand->label,
        ));
    }

    public function update(Request $request, CatalogBrandIcon $brand): RedirectResponse
    {
        $brand->update($this->validated($request));

        return back()->with('status', sprintf('Brand %s diperbarui.', $brand->label));
    }

    public function clearCustomEmoji(CatalogBrandIcon $brand): RedirectResponse
    {
        $brand->update(['custom_emoji_id' => null]);

        return back()->with('status', sprintf(
            'Emoji %s dihapus. Tombol katalog kembali memakai emoji bawaan.',
            $brand->label,
        ));
    }

    /**
     * @return array{label: string, patterns: list<string>, fallback_emoji: string, sort_order: int}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:160'],
            'patterns' => ['required', 'string', 'max:2000'],
            'fallback_emoji' => ['required', 'string', 'max:32'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        $patterns = $this->patterns((string) $validated['patterns']);
        if ($patterns === []) {
            throw ValidationException::withMessages(['patterns' => 'Isi minimal satu pola nama brand.']);
        }

        return [
            'label' => trim((string) $validated['label']),
            'patterns' => $patterns,
            'fallback_emoji' => trim((string) $validated['fallback_emoji']),
            'sort_order' => (int) $validated['sort_order'],
        ];
    }

    /**
     * @return list<string>
     */
    private function patterns(string $value): array
    {
        $patterns = [];
        foreach (preg_split('/[\r\n,]+/', $value) ?: [] as $pattern) {
            $pattern = mb_strtolower(trim((string) $pattern));
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    private function uniqueSlug(string $label): string
    {
        $base = Str::slug($label) ?: 'brand';
        $slug = $base;
        $suffix = 2;

        while (CatalogBrandIcon::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
