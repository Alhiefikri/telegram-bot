<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\ProductDescriptionSectionProtocol;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminProductUpdateController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, Product $product, AuditLogger $auditLogger, ProductDescriptionSectionProtocol $descriptions): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        Gate::authorize('update', $product);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:1000'],
            'features' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Nama produk wajib diisi.',
            'price_amount.required' => 'Harga wajib diisi.',
            'price_amount.integer' => 'Harga harus berupa angka tanpa pemisah.',
            'description.required' => 'Deskripsi singkat wajib diisi.',
            'category_id.exists' => 'Kategori tidak valid.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'produk'])
                ->withErrors($validator, 'productUpdate')
                ->withInput($request->except(['_token', '_method']) + ['product_id' => $product->id]);
        }

        $validated = $validator->validated();
        $oldValues = $product->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

        $product->fill([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $descriptions->encode(
                $validated['description'],
                $validated['features'] ?? '',
                $validated['specifications'] ?? '',
            ),
            'price_amount' => (int) $validated['price_amount'],
            'currency' => 'IDR',
            'is_active' => $request->boolean('is_active'),
        ]);
        $product->save();

        $newValues = $product->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

        $auditLogger->log(
            'product.updated',
            $product,
            $oldValues,
            $newValues,
            $user,
            $request->ip(),
        );

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Produk {$product->name} berhasil diperbarui.");
    }
}
