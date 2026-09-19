<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductQuantityPricing;
use App\Services\Admin\ProductFamilyCategoryCoherence;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminProductUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms, ProductPriceChangeNotificationService $priceNotifications, ProductFamilyCategoryCoherence $coherence, AdminProductQuantityPricing $tierPricing): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $newImagePath = $request->hasFile('image') ? $images->store($request->file('image')) : null;
        $newTelegramImagePath = $request->hasFile('telegram_image') ? $images->storeTelegram($request->file('telegram_image')) : null;
        $oldImagePath = null;
        $oldTelegramImagePath = null;

        try {
            $event = DB::transaction(function () use ($product, $validated, $request, $auditLogger, $user, $priceNotifications, $newImagePath, $newTelegramImagePath, &$oldImagePath, &$oldTelegramImagePath, $coherence, $tierPricing) {
                $categoryId = $validated['category_id'] ?? null;

                $locked = $coherence->lockForCategoryChange($product, $categoryId);
                $oldValues = $locked->only(['category_id', 'name', 'icon', 'description', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'telegram_image_path', 'is_active', 'featured_rank', 'stock', 'reserved_stock']);
                $tierAudit = [
                    'before' => $tierPricing->snapshot($locked),
                    'after' => [],
                ];
                $oldImagePath = $oldValues['image_path'] ?? null;
                $oldTelegramImagePath = $oldValues['telegram_image_path'] ?? null;

                $locked->fill([
                    'category_id' => $validated['category_id'] ?? null,
                    'name' => $validated['name'],
                    'icon' => $request->exists('icon') ? ($validated['icon'] ?? null) : $locked->icon,
                    'description' => $validated['description'],
                    ...$request->merchandisingAttributes(),
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
                if ($newTelegramImagePath !== null) {
                    $locked->telegram_image_path = $newTelegramImagePath;
                }
                $locked->save();
                if ($request->hasQuantityPriceTiersInput()) {
                    $tierAudit = $tierPricing->replace($locked, $request->quantityPriceTiers());
                } else {
                    $tierAudit['after'] = $tierPricing->snapshot($locked);
                }
                $oldValues['quantity_price_tiers'] = $tierAudit['before'];
                $newValues = $locked->only(['category_id', 'name', 'icon', 'description', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'telegram_image_path', 'is_active', 'featured_rank', 'stock', 'reserved_stock']);
                $newValues['quantity_price_tiers'] = $tierAudit['after'];

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
            if ($newTelegramImagePath !== null) {
                $images->deleteManagedImage($newTelegramImagePath);
            }

            if ($exception instanceof ProductFamilyCategoryMismatch) {
                return $forms->productUpdateBusinessValidationError($request, $product, 'category_id', $exception->getMessage());
            }

            throw $exception;
        }

        if ($newImagePath !== null) {
            $images->deleteManagedImage($oldImagePath);
        }
        if ($newTelegramImagePath !== null) {
            $images->deleteManagedImage($oldTelegramImagePath);
        }

        if ($event !== null) {
            SendProductPriceChangeNotifications::dispatch($event->id, now()->toISOString())
                ->onQueue('telegram-broadcast')
                ->afterCommit();
        }

        return $forms->productUpdated($product->fresh());
    }
}
