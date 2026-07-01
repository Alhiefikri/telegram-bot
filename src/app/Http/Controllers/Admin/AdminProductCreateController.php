<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductDescriptionSectionProtocol;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminProductCreateController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, AuditLogger $auditLogger, TelegramProductNotificationService $notifications, ProductDescriptionSectionProtocol $descriptions, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

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
            return $forms->productCreateValidationErrors($validator);
        }

        $validated = $validator->validated();

        $product = new Product([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $descriptions->encode(
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

        $notifications->notifyProductCreated($product);

        return $forms->productCreated($product->name);
    }
}
