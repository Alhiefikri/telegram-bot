<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DogfoodTiming
{
    private static array $queries = ['count' => 0, 'total_ms' => 0.0, 'slowest_ms' => 0.0];

    public static function enabled(): bool
    {
        return (bool) config('services.dogfood_timing.enabled', false);
    }

    public static function now(): int
    {
        return hrtime(true);
    }

    public static function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    public static function correlation(int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(hash_hmac('sha256', (string) $value, (string) config('app.key', 'dogfood')), 0, 16);
    }

    public static function queueWaitMs(?string $jobEnqueuedAt): ?int
    {
        if (! $jobEnqueuedAt) {
            return null;
        }

        return max(0, now()->diffInMilliseconds(Carbon::parse($jobEnqueuedAt), true));
    }

    public static function recordQuery(QueryExecuted $query): void
    {
        if (! self::enabled()) {
            return;
        }

        self::$queries['count']++;
        self::$queries['total_ms'] += (float) $query->time;
        self::$queries['slowest_ms'] = max(self::$queries['slowest_ms'], (float) $query->time);
    }

    public static function resetQueries(): void
    {
        self::$queries = ['count' => 0, 'total_ms' => 0.0, 'slowest_ms' => 0.0];
    }

    public static function queryStats(): array
    {
        return [
            'db_query_count' => self::$queries['count'],
            'db_query_total_ms' => round(self::$queries['total_ms'], 2),
            'db_query_slowest_ms' => round(self::$queries['slowest_ms'], 2),
        ];
    }

    public static function log(string $event, array $context): void
    {
        if (! self::enabled()) {
            return;
        }

        Log::info($event, $context);
    }
}
