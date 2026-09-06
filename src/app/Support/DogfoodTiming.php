<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DogfoodTiming
{
    private static array $queries = ['count' => 0, 'total_ms' => 0.0, 'slowest_ms' => 0.0];

    private static array $queryTimes = [];

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

    /**
     * Return the worker's resident set size when procfs is available.
     *
     * PHP's allocator usage is useful for local tests but does not include
     * every page held by the worker. The procfs value is a better production
     * signal; the allocator value is a deterministic fallback for platforms
     * without procfs.
     */
    public static function residentMemoryBytes(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status)
            && preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $status, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        return memory_get_usage(true);
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
        self::$queryTimes[] = (float) $query->time;
    }

    public static function resetQueries(): void
    {
        self::$queries = ['count' => 0, 'total_ms' => 0.0, 'slowest_ms' => 0.0];
        self::$queryTimes = [];
    }

    public static function queryStats(): array
    {
        return [
            'db_query_count' => self::$queries['count'],
            'db_query_total_ms' => round(self::$queries['total_ms'], 2),
            'db_query_slowest_ms' => round(self::$queries['slowest_ms'], 2),
        ];
    }

    public static function querySnapshot(): array
    {
        return self::$queries + ['index' => count(self::$queryTimes)];
    }

    public static function queryDelta(array $before): array
    {
        $queryTimes = array_slice(self::$queryTimes, (int) ($before['index'] ?? 0));

        return [
            'db_query_count' => count($queryTimes),
            'db_query_total_ms' => round(array_sum($queryTimes), 2),
            'db_query_slowest_ms' => round(max($queryTimes ?: [0]), 2),
        ];
    }

    public static function time(string $event, array $context, callable $callback): mixed
    {
        $startedAt = self::now();
        $querySnapshot = self::querySnapshot();
        $status = 'ok';

        try {
            return $callback();
        } catch (\Throwable $e) {
            $status = 'error';
            $context['exception'] = $e::class;

            throw $e;
        } finally {
            self::log($event, $context + self::queryDelta($querySnapshot) + [
                'status' => $status,
                'duration_ms' => self::elapsedMs($startedAt),
            ]);
        }
    }

    public static function log(string $event, array $context): void
    {
        if (! self::enabled()) {
            return;
        }

        $channel = config('services.dogfood_timing.log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->info($event, $context);

            return;
        }

        Log::info($event, $context);
    }
}
