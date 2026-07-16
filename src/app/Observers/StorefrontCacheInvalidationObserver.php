<?php

namespace App\Observers;

use App\Storefront\StorefrontResponseCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class StorefrontCacheInvalidationObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(Model $model): void
    {
        $this->invalidate();
    }

    public function deleted(Model $model): void
    {
        $this->invalidate();
    }

    public function restored(Model $model): void
    {
        $this->invalidate();
    }

    private function invalidate(): void
    {
        app(StorefrontResponseCache::class)->invalidate();
    }
}
