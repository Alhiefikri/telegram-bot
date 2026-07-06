<?php

namespace App\Services\Telegram;

use App\Models\SalesProofPost;
use Illuminate\Support\Facades\Cache;

class SalesProofPostNumbering
{
    private const CACHE_PUBLISHED_COUNT = 'sales-proof-posts:published-count';

    public function nextNumber(): int
    {
        return $this->publishedCount() + 1;
    }

    public function reserveNextNumber(): int
    {
        $publishedCount = $this->publishedCount();
        if (! Cache::has(self::CACHE_PUBLISHED_COUNT) || $publishedCount > $this->cachedPublishedCount()) {
            Cache::forever(self::CACHE_PUBLISHED_COUNT, $publishedCount);
        }

        return (int) Cache::increment(self::CACHE_PUBLISHED_COUNT);
    }

    public function sentCount(): int
    {
        return (int) SalesProofPost::query()->where('status', SalesProofPost::STATUS_SENT)->count();
    }

    public function publishedCount(): int
    {
        return max($this->sentCount(), $this->cachedPublishedCount());
    }

    private function cachedPublishedCount(): int
    {
        $count = Cache::get(self::CACHE_PUBLISHED_COUNT);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }
}
