<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditLogger;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminProductCreateController extends Controller
{
    public function __invoke(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $panel = Filament::getPanel('admin');

        abort_unless($user->canAccessPanel($panel), 403);

        Gate::authorize('create', Product::class);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:1000'],
            'features' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Nama produk wajib diisi.',
            'price_amount.required' => 'Harga wajib diisi.',
            'price_amount.integer' => 'Harga harus berupa angka tanpa pemisah.',
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'category_id.exists' => 'Kategori tidak valid.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'tambah'])
                ->withErrors($validator, 'productCreate')
                ->withInput();
        }

        $validated = $validator->validated();

        $product = new Product([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $this->descriptionFromSections(
                $validated['description'],
                $validated['features'] ?? '',
                $validated['specifications'] ?? '',
            ),
            'price_amount' => (int) $validated['price_amount'],
            'currency' => 'IDR',
            'image_path' => null,
            'is_active' => true,
        ]);
        $product->stock = 0;
        $product->reserved_stock = 0;
        $product->save();

        $auditLogger->log(
            'product.created',
            $product,
            null,
            $product->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']),
            $user,
            $request->ip(),
        );

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Produk {$product->name} berhasil dibuat. Tambahkan stok saat item sudah siap.");
    }

    private function descriptionFromSections(string $description, string $features, string $specifications): string
    {
        $sections = [trim($description)];

        $featureRows = $this->lines($features);
        if ($featureRows !== []) {
            $sections[] = "Fitur utama:\n- ".implode("\n- ", $featureRows);
        }

        $specificationRows = $this->lines($specifications);
        if ($specificationRows !== []) {
            $sections[] = "Paket/spesifikasi:\n- ".implode("\n- ", $specificationRows);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        return collect(preg_split('/\R/u', $value) ?: [])
            ->map(fn (string $row): string => trim($row))
            ->filter()
            ->values()
            ->all();
    }
}
