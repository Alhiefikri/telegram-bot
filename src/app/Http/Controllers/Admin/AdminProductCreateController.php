<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramProductNotificationService;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;

class AdminProductCreateController extends Controller
{
    public function __invoke(StoreProductRequest $request, AuditLogger $auditLogger, TelegramProductNotificationService $notifications, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();

        $product = new Product([
            'category_id' => $validated['category_id'] ?? null,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'delivery_note' => $validated['delivery_note'] ?? null,
            'price_amount' => (int) $validated['price_amount'],
            'usdt_price_amount' => $validated['usdt_price_amount'] ?? null,
            'fake_discount_price' => isset($validated['fake_discount_price']) ? (int) $validated['fake_discount_price'] : null,
            'currency' => 'IDR',
            'image_path' => $request->hasFile('image') ? $images->store($request->file('image')) : null,
            'is_active' => true,
        ]);
        $product->stock = 0;
        $product->reserved_stock = 0;
        $product->save();

        $auditLogger->log(
            'product.created',
            $product,
            null,
            $product->only(['category_id', 'name', 'description', 'price_amount', 'usdt_price_amount', 'fake_discount_price', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']),
            $user,
            $request->ip(),
        );

        $result = $notifications->notifyProductCreated($product);
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_create', $startedAt, $result);

        return $forms->productCreated($product->name);
    }
}
