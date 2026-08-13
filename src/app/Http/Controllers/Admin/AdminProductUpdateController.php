<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminProductUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms, ProductPriceChangeNotificationService $priceNotifications): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $newImagePath = $request->hasFile('image') ? $images->store($request->file('image')) : null;
        $oldImagePath = null;

        try {
            $event = DB::transaction(function () use ($product, $validated, $request, $auditLogger, $user, $priceNotifications, $newImagePath, &$oldImagePath) {
                $categoryId = $validated['category_id'] ?? null;

                if ($categoryId !== null) {
                    Category::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
                }

                $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                $oldValues = $locked->only(['category_id', 'name', 'description', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);
                $oldImagePath = $oldValues['image_path'] ?? null;

                $locked->fill([
                    'category_id' => $validated['category_id'] ?? null,
                    'name' => $validated['name'],
                    'description' => $validated['description'],
                    'delivery_note' => $validated['delivery_note'] ?? null,
                    'usage_guide_title' => $validated['usage_guide_title'] ?? null,
                    'usage_guide_url' => $validated['usage_guide_url'] ?? null,
                    'price_amount' => (int) $validated['price_amount'],
                    'usdt_price_amount' => $validated['usdt_price_amount'] ?? null,
                    'currency' => 'IDR',
                    'is_active' => $request->boolean('is_active'),
                ]);

                if ($newImagePath !== null) {
                    $locked->image_path = $newImagePath;
                }
                $locked->save();

                $newValues = $locked->only(['category_id', 'name', 'description', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

                $auditLogger->log(
                    'product.updated',
                    $locked,
                    $oldValues,
                    $newValues,
                    $user,
                    $request->ip(),
                );

                return $priceNotifications->createEventForCommittedUpdate($locked, $oldValues, $newValues);
            }, 5);
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                $images->deleteManagedImage($newImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null) {
            $images->deleteManagedImage($oldImagePath);
        }

        if ($event !== null) {
            SendProductPriceChangeNotifications::dispatch($event->id, now()->toISOString())
                ->onQueue('telegram-broadcast')
                ->afterCommit();
        }

        return $forms->productUpdated($product->fresh());
    }
}
