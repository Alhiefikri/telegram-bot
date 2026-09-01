<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramProductNotificationService;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminProductCreateController extends Controller
{
    public function __invoke(StoreProductRequest $request, AuditLogger $auditLogger, TelegramProductNotificationService $notifications, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();

        $imagePath = $request->hasFile('image') ? $images->store($request->file('image')) : null;

        try {
            $product = DB::transaction(function () use ($validated, $imagePath, $auditLogger, $user, $request): Product {
                $categoryId = $validated['category_id'] ?? null;

                if ($categoryId !== null) {
                    Category::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
                }

                $product = new Product([
                    'category_id' => $categoryId,
                    'name' => $validated['name'],
                    'icon' => $validated['icon'] ?? null,
                    'description' => $validated['description'],
                    ...$request->merchandisingAttributes(),
                    'delivery_note' => $validated['delivery_note'] ?? null,
                    'usage_guide_title' => $validated['usage_guide_title'] ?? null,
                    'usage_guide_url' => $validated['usage_guide_url'] ?? null,
                    'price_amount' => (int) $validated['price_amount'],
                    'usdt_price_amount' => $validated['usdt_price_amount'] ?? null,
                    'currency' => 'IDR',
                    'image_path' => $imagePath,
                    'is_active' => true,
                ]);
                $product->stock = 0;
                $product->reserved_stock = 0;
                $product->save();

                $auditLogger->log(
                    'product.created',
                    $product,
                    null,
                    $product->only(['category_id', 'name', 'icon', 'description', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'support_policy', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active', 'featured_rank', 'stock', 'reserved_stock']),
                    $user,
                    $request->ip(),
                );

                return $product;
            }, 5);
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                $images->deleteManagedImage($imagePath);
            }

            throw $exception;
        }

        $result = $notifications->notifyProductCreated($product);
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_create', $startedAt, $result);

        return $forms->productCreated($product);
    }
}
