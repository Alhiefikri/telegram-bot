<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductQuantityPricing;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Admin\ProductFamilyCategoryCoherence;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminProductUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms, ProductPriceChangeNotificationService $priceNotifications, ProductFamilyCategoryCoherence $coherence, AdminProductQuantityPricing $tierPricing, AdminProductWorkspaceData $workspace): RedirectResponse|JsonResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $section = $validated['workspace_section'] ?? null;
        $newImagePath = null;
        $newTelegramImagePath = null;
        $newTelegramChannelImagePath = null;
        $oldImagePath = null;
        $oldTelegramImagePath = null;
        $oldTelegramChannelImagePath = null;

        try {
            $newImagePath = ($section === null || $section === 'appearance') && $request->hasFile('image') ? $images->store($request->file('image')) : null;
            $newTelegramImagePath = ($section === null || $section === 'appearance') && $request->hasFile('telegram_image') ? $images->storeTelegram($request->file('telegram_image')) : null;
            $newTelegramChannelImagePath = ($section === null || $section === 'appearance') && $request->hasFile('telegram_channel_image') ? $images->storeTelegramChannel($request->file('telegram_channel_image')) : null;
            $event = DB::transaction(function () use ($product, $validated, $request, $auditLogger, $user, $priceNotifications, $newImagePath, $newTelegramImagePath, $newTelegramChannelImagePath, &$oldImagePath, &$oldTelegramImagePath, &$oldTelegramChannelImagePath, $coherence, $tierPricing, $section, $workspace) {
                if ($section === null || ($section === 'detail' && array_key_exists('category_id', $validated))) {
                    $categoryId = array_key_exists('category_id', $validated) ? $validated['category_id'] : ($section === null ? null : $product->category_id);
                    $locked = $coherence->lockForCategoryChange($product, $categoryId);
                } else {
                    $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                }
                if ($section !== null) {
                    abort_unless(hash_equals($workspace->version($locked, $section), $validated['workspace_version']), 409, 'Bagian ini telah berubah. Muat ulang sebelum menyimpan.');
                }
                $auditFields = ['category_id', 'name', 'icon', 'description', 'description_ms', 'description_en', 'product_type', 'access_duration', 'region', 'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation', 'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en', 'support_policy', 'usage_guide_title', 'usage_guide_url', 'price_amount', 'usdt_price_amount', 'reseller_price_amount', 'reseller_usdt_price_amount', 'currency', 'image_path', 'telegram_image_path', 'telegram_channel_image_path', 'is_active', 'featured_rank', 'stock', 'reserved_stock'];
                $oldValues = $locked->only($auditFields);
                $tierAudit = [
                    'before' => $tierPricing->snapshot($locked),
                    'after' => [],
                ];
                $oldImagePath = $oldValues['image_path'] ?? null;
                $oldTelegramImagePath = $oldValues['telegram_image_path'] ?? null;
                $oldTelegramChannelImagePath = $oldValues['telegram_channel_image_path'] ?? null;

                if ($section !== null) {
                    $locked->fill(array_intersect_key($validated, array_flip(AdminProductWorkspaceData::SECTIONS[$section])));
                } else {
                    $locked->fill([
                        'category_id' => $validated['category_id'] ?? null,
                        'name' => $validated['name'],
                        'icon' => $request->exists('icon') ? ($validated['icon'] ?? null) : $locked->icon,
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
                        'reseller_price_amount' => $request->exists('reseller_price_amount') ? ($validated['reseller_price_amount'] ?? null) : $locked->reseller_price_amount,
                        'reseller_usdt_price_amount' => $request->exists('reseller_usdt_price_amount') ? ($validated['reseller_usdt_price_amount'] ?? null) : $locked->reseller_usdt_price_amount,
                        'currency' => 'IDR',
                        'is_active' => $request->boolean('is_active'),
                    ]);
                }

                if ($newImagePath !== null) {
                    $locked->image_path = $newImagePath;
                }
                if ($newTelegramImagePath !== null) {
                    $locked->telegram_image_path = $newTelegramImagePath;
                }
                if ($newTelegramChannelImagePath !== null) {
                    $locked->telegram_channel_image_path = $newTelegramChannelImagePath;
                }
                if ($section === 'price') {
                    $tiers = $request->hasQuantityPriceTiersInput() ? $request->quantityPriceTiers() : $tierPricing->snapshot($locked);
                    try {
                        app(ProductQuantityPricing::class)->validateSchedule((int) $locked->price_amount, $locked->usdt_price_amount, $tiers);
                    } catch (\InvalidArgumentException) {
                        throw ValidationException::withMessages(['quantity_price_tiers' => 'Harga dasar harus mempertahankan jadwal harga bertingkat yang valid.']);
                    }
                }
                try {
                    app(ProductQuantityPricing::class)->validateResellerOverrides(
                        $locked->reseller_price_amount === null ? null : (int) $locked->reseller_price_amount,
                        $locked->usdt_price_amount === null ? null : (int) $locked->usdt_price_amount,
                        $locked->reseller_usdt_price_amount === null ? null : (int) $locked->reseller_usdt_price_amount,
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        str_contains($exception->getMessage(), 'reseller IDR price') ? 'reseller_price_amount' : 'reseller_usdt_price_amount' => 'Harga reseller USDT hanya berlaku jika produk memiliki harga USDT dasar di atas 0. Isi Harga USDT atau kosongkan harga reseller USDT.',
                    ]);
                }
                $locked->save();
                if ($request->hasQuantityPriceTiersInput()) {
                    $tierAudit = $tierPricing->replace($locked, $request->quantityPriceTiers());
                } else {
                    $tierAudit['after'] = $tierPricing->snapshot($locked);
                }
                $oldValues['quantity_price_tiers'] = $tierAudit['before'];
                $newValues = $locked->only($auditFields);
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
            if ($newTelegramChannelImagePath !== null) {
                $images->deleteManagedImage($newTelegramChannelImagePath);
            }

            if ($exception instanceof ValidationException && $request->expectsJson()) {
                return response()->json([
                    'message' => 'Periksa kembali isian yang ditandai.',
                    'errors' => $exception->errors(),
                ], 422)->header('Cache-Control', 'no-store, private');
            }
            if ($exception instanceof ProductFamilyCategoryMismatch) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => $exception->getMessage(), 'errors' => ['category_id' => [$exception->getMessage()]]], 422);
                }

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
        if ($newTelegramChannelImagePath !== null) {
            $images->deleteManagedImage($oldTelegramChannelImagePath);
        }

        if ($event !== null) {
            SendProductPriceChangeNotifications::dispatch($event->id, now()->toISOString())
                ->onQueue('telegram-broadcast')
                ->afterCommit();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Perubahan produk disimpan.', 'workspace' => $workspace->for($product)])->header('Cache-Control', 'no-store, private');
        }

        return $forms->productUpdated($product->fresh());
    }
}
