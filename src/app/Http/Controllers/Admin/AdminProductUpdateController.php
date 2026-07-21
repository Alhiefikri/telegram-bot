<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AdminProductUpdateController extends Controller
{
    public function __invoke(UpdateProductRequest $request, Product $product, AuditLogger $auditLogger, ProductImageOptimizer $images, AdminProductDashboardFormProtocol $forms, ProductPriceChangeNotificationService $priceNotifications): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
        $event = DB::transaction(function () use ($product, $validated, $request, $images, $auditLogger, $user, $priceNotifications) {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $oldValues = $locked->only(['category_id', 'name', 'description', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

            $locked->fill([
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'description' => $validated['description'],
                'delivery_note' => $validated['delivery_note'] ?? null,
                'price_amount' => (int) $validated['price_amount'],
                'usdt_price_amount' => $validated['usdt_price_amount'] ?? null,
                'currency' => 'IDR',
                'is_active' => $request->boolean('is_active'),
            ]);

            if ($request->hasFile('image')) {
                $locked->image_path = $images->replace($locked->image_path, $request->file('image'));
            }
            $locked->save();

            $newValues = $locked->only(['category_id', 'name', 'description', 'price_amount', 'usdt_price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']);

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

        if ($event !== null) {
            SendProductPriceChangeNotifications::dispatch($event->id, now()->toISOString())
                ->onQueue('notifications')
                ->afterCommit();
        }

        return $forms->productUpdated($product->fresh()->name);
    }
}
