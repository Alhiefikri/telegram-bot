<?php

namespace App\Services\Telegram;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

final class TelegramBroadcastRateLimiter
{
    private const CACHE_STATE_KEY = 'telegram:admin-broadcast:send-rate:v1';

    private const CACHE_LOCK_KEY = 'telegram:admin-broadcast:send-rate:v1:lock';

    private const TELEGRAM_DEFAULT_RATE = 30;

    private const WINDOW_MILLISECONDS = 1000;

    private const LOCK_SECONDS = 2;

    private const STATE_TTL_SECONDS = 2;

    public function tryAcquire(): TelegramBroadcastRateLimitDecision
    {
        $nowMilliseconds = (int) now()->getPreciseTimestamp(3);
        $lockProvider = Cache::store()->getStore();

        if (! $lockProvider instanceof LockProvider) {
            return TelegramBroadcastRateLimitDecision::deferred(1);
        }

        $lock = $lockProvider->lock(self::CACHE_LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return TelegramBroadcastRateLimitDecision::deferred(1);
        }

        try {
            $cache = Cache::store();
            $timestamps = $this->activeTimestamps($cache->get(self::CACHE_STATE_KEY), $nowMilliseconds);
            $limit = $this->maxMessagesPerWindow();

            if (count($timestamps) >= $limit) {
                $nextAvailableMilliseconds = min($timestamps) + self::WINDOW_MILLISECONDS;

                return TelegramBroadcastRateLimitDecision::deferred(
                    $this->secondsUntil($nextAvailableMilliseconds, $nowMilliseconds),
                );
            }

            $timestamps[] = $nowMilliseconds;
            $cache->put(self::CACHE_STATE_KEY, $timestamps, self::STATE_TTL_SECONDS);

            return TelegramBroadcastRateLimitDecision::allowed();
        } finally {
            $lock->release();
        }
    }

    public function maxMessagesPerWindow(): int
    {
        return min(
            self::TELEGRAM_DEFAULT_RATE - 1,
            max(1, (int) config('services.telegram.broadcast_rate_limit_per_second', 25)),
        );
    }

    /**
     * @return list<int>
     */
    private function activeTimestamps(mixed $state, int $nowMilliseconds): array
    {
        if (! is_array($state)) {
            return [];
        }

        $activeTimestamps = array_filter($state, function (mixed $timestamp) use ($nowMilliseconds): bool {
            return is_numeric($timestamp)
                && (int) $timestamp > $nowMilliseconds - self::WINDOW_MILLISECONDS;
        });

        return array_values(array_map(
            static fn (mixed $timestamp): int => (int) $timestamp,
            $activeTimestamps,
        ));
    }

    private function secondsUntil(int $targetMilliseconds, int $nowMilliseconds): int
    {
        return max(1, (int) ceil(max(0, $targetMilliseconds - $nowMilliseconds) / 1000));
    }
}
