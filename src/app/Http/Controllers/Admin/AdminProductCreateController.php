<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductQuantityPricing;
use App\Services\Admin\ProductFamilyManagementService;
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
    public function __invoke(StoreProductRequest $request, AuditLogger $auditLogger, TelegramProductNotificationService $notifications, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms, AdminProductQuantityPricing $tierPricing, ProductFamilyManagementService $families): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();

        $imagePath = null;
        $telegramImagePath = null;
        $telegramChannelImagePath = null;

        try {
            $imagePath = $request->hasFile('image') ? $images->store($request->file('image')) : null;
            $telegramImagePath = $request->hasFile('telegram_image') ? $images->storeTelegram($request->file('telegram_image')) : null;
            $telegramChannelImagePath = $request->hasFile('telegram_channel_image') ? $images->storeTelegramChannel($request->file('telegram_channel_image')) : null;
            $product = DB::transaction(function () use ($validated, $imagePath, $telegramImagePath, $telegramChannelImagePath, $auditLogger, $user, $request, $tierPricing, $families): Product {
                $categoryId = $validated['category_id'] ?? null;

                if ($categoryId !== null) {
                    Category::query()->whereKey($categoryId)->lockForUpdate()->firstOrFail();
                }

                $product = new Product([
                    'category_id' => $categoryId,
                    'name' => $validated['name'],
                    'icon' => $validated['icon'] ?? null,
                    'description' => $validated['description'],
                    'description_ms' => $validated['description_ms'] ?? null,
                    'description_en' => $validated['description_en'] ?? null,
                    ...$request->merchandisingAttributes(),
                    'warranty_policy_ms' => $validated['warranty_policy_ms'] ?? null,
                    'warranty_policy_en' => $validated['warranty_policy_en'] ?? null,
                    'delivery_note' => $validated['delivery_note'] ?? null,
                    'usage_guide_title' => $validated['usage_guide_title'] ?? null,
                    'usage_guide_url' => $validated['usage_guide_url'] ?? null,
                    'price_amount' => (int) $validated['price_amount'],
                    'usdt_price_amount' => $validated['usdt_price_amount'] ?? null,
                    'reseller_price_amount' => $validated['reseller_price_amount'] ?? null,
                    'reseller_usdt_price_amount' => $validated['reseller_usdt_price_amount'] ?? null,
                    'currency' => 'IDR',
                    'image_path' => $imagePath,
                    'telegram_image_path' => $telegramImagePath,
                    'telegram_channel_image_path' => $telegramChannelImagePath,
                    'is_active' => true,
                ]);
                $product->stock = 0;
                $product->reserved_stock = 0;
                $product->save();

                $tierChange = $tierPricing->replace($product, $request->quantityPriceTiers());

                $newValues = $product->only(['category_id', 'name', 'icon', 'description', 'description_ms', 'description_en', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'reseller_price_amount', 'reseller_usdt_price_amount', 'currency', 'image_path', 'telegram_image_path', 'telegram_channel_image_path', 'is_active', 'featured_rank', 'stock', 'reserved_stock']);
                $newValues['quantity_price_tiers'] = $tierChange['after'];

                $auditLogger->log(
                    'product.created',
                    $product,
                    null,
                    $newValues,
                    $user,
                    $request->ip(),
                );

                $familyId = $validated['product_family_id'] ?? null;
                if ($familyId !== null) {
                    $families->updateMembership($product, (int) $familyId, 0, null, $user, $request->ip());
                }

                return $product;
            }, 5);
        } catch (ProductFamilyCategoryMismatch $exception) {
            $this->discardUploads($images, $imagePath, $telegramImagePath, $telegramChannelImagePath);

            return $forms->productCreateBusinessValidationError($request, 'product_family_id', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->discardUploads($images, $imagePath, $telegramImagePath, $telegramChannelImagePath);

            throw $exception;
        }

        $result = $notifications->notifyProductCreated($product);
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_create', $startedAt, $result);

        return $forms->productCreated($product);
    }

    /**
     * A rejected create rolls back the database work, so the already stored
     * uploads must never survive the request.
     */
    private function discardUploads(ProductImageOptimizer $images, ?string ...$paths): void
    {
        foreach ($paths as $path) {
            if ($path !== null) {
                $images->deleteManagedImage($path);
            }
        }
    }
}
