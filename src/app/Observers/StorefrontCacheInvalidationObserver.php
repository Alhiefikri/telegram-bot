<?php

namespace App\Observers;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use App\Services\Bsc\BscReadiness;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductQuantityPriceTier;
use App\Services\Telegram\TelegramCatalogProjectionCache;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class StorefrontCacheInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Use mutation-specific events so atomic increment/decrement updates are
     * invalidated without duplicating the save or restore paths.
     *
     * Restores persist through save(), so updated is the single restore hook.
     */
    public function created(Model $model): void
    {
        $this->invalidate($model);
    }

    public function updated(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        app(StorefrontResponseCache::class)->invalidate();

        if ($model instanceof Product || $model instanceof ProductFamily || $model instanceof Category || $model instanceof ProductQuantityPriceTier) {
            app(TelegramCatalogProjectionCache::class)->invalidate();
        }

        if ($model instanceof ManualPaymentSetting || $model instanceof BscPaymentReceiver) {
            app(BscReadiness::class)->invalidate();
        }
    }
}
