<?php

namespace App\Observers;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use App\Services\Bsc\BscReadiness;
use App\Storefront\StorefrontResponseCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class StorefrontCacheInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    public function restored(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        app(StorefrontResponseCache::class)->invalidate();

        if ($model instanceof ManualPaymentSetting || $model instanceof BscPaymentReceiver) {
            app(BscReadiness::class)->invalidate();
        }
    }
}
