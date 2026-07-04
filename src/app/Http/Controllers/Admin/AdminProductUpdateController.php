<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductDescriptionSectionProtocol;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class AdminProductUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger, ProductDescriptionSectionProtocol $descriptions, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $oldValues = $product->only(['category_id', 'name', 'description', 'price_amount', 'fake_discount_price', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

        $product->fill([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $descriptions->encode(
                $validated['description'],
                $validated['features'] ?? '',
                $validated['specifications'] ?? '',
            ),
            'price_amount' => (int) $validated['price_amount'],
            'fake_discount_price' => isset($validated['fake_discount_price']) ? (int) $validated['fake_discount_price'] : null,
            'currency' => 'IDR',
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($request->hasFile('image')) {
            $product->image_path = $images->replace($product->image_path, $request->file('image'));
        }
        $product->save();

        $newValues = $product->only(['category_id', 'name', 'description', 'price_amount', 'fake_discount_price', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

        $auditLogger->log(
            'product.updated',
            $product,
            $oldValues,
            $newValues,
            $user,
            $request->ip(),
        );

        return $forms->productUpdated($product->name);
    }
}
