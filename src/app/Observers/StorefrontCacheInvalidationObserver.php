<?php

namespace App\Observers;

use App\Models\BscPaymentReceiver;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductQuantityPriceTier;
use App\Services\Bsc\BscReadiness;
use App\Services\Telegram\TelegramCatalogProjectionCache;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StorefrontCacheInvalidationObserver
{
    /**
     * Use mutation-specific events so atomic increment/decrement updates are
     * invalidated without duplicating the save or restore paths.
     *
     * Restores persist through save(), so updated is the single restore hook.
     */
    public function created(Model $model): void
    {
        $this->invalidateAfterCommit($model);
    }

    public function updated(Model $model): void
    {
        // Inspect this save before another save can replace the model's change set.
        if ($model instanceof Product && $this->isSafeSupplierFreshnessRefresh($model)) {
            $previous = Carbon::parse($model->getPrevious()['supplier_available_until']);
            $model->getConnection()->afterCommit(function () use ($model, $previous): void {
                // A long transaction may outlive the evidence used by a concurrent rebuild.
                if (! $previous->isFuture()) {
                    $this->invalidateAfterCommit($model);
                }
            });

            return;
        }

        $this->invalidateAfterCommit($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidateAfterCommit($model);
    }

    private function isSafeSupplierFreshnessRefresh(Product $product): bool
    {
        if (! $product->wasChanged('supplier_available_until')) {
            return false;
        }

        foreach ($product->getChanges() as $attribute => $_value) {
            if ($attribute !== 'supplier_available_until' && $attribute !== 'updated_at') {
                return false;
            }
        }

        $previous = $product->getPrevious()['supplier_available_until'] ?? null;
        $until = $product->supplier_available_until;
        if ($previous === null || $until === null) {
            return false;
        }

        $previous = Carbon::parse($previous);

        // Existing entries keep their original TTL; never reuse an expired observation.
        return $previous->isFuture() && $until->greaterThanOrEqualTo($previous);
    }

    private function invalidateAfterCommit(Model $model): void
    {
        $telegram = $model instanceof Product
            || $model instanceof ProductFamily
            || $model instanceof Category
            || $model instanceof ProductQuantityPriceTier
            || $model instanceof Order
            || $model instanceof OrderItem;
        $readiness = $model instanceof ManualPaymentSetting || $model instanceof BscPaymentReceiver;

        $model->getConnection()->afterCommit(static function () use ($telegram, $readiness): void {
            app(StorefrontResponseCache::class)->invalidate();
            if ($telegram) {
                app(TelegramCatalogProjectionCache::class)->invalidate();
            }
            if ($readiness) {
                app(BscReadiness::class)->invalidate();
            }
        });
    }
}
