<?php

namespace App\Services\Operations;

use App\Enums\BalanceOperationMetric;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retry boundary for balance money transactions (#558).
 *
 * Behaviour matches `DB::transaction($callback, $attempts)`: only database
 * concurrency errors (deadlock, serialization failure, lock wait timeout) are
 * retried; every other failure escapes unchanged. The wrapper additionally
 * records one bounded `lock_retry` metric per retried attempt with a duration
 * bucket, so operators can see lock contention without a scrape endpoint.
 *
 * Retry events are buffered and written only after the transaction loop ends,
 * so the metrics insert can never participate in (or roll back) the money
 * transaction.
 */
final class BalanceOperationRetry
{
    public function __construct(private readonly BalanceOperationEventRecorder $events) {}

    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        $attempts = max(1, $attempts);

        // Nested callers must not retry: the outer transaction is already
        // aborted once a concurrency error escapes an inner savepoint.
        if ($attempts === 1 || DB::transactionLevel() > 0) {
            return DB::transaction($callback, 1);
        }

        $attempt = 0;
        /** @var list<array{string, int, int}> $retries */
        $retries = [];

        while (true) {
            $attempt++;
            $startedAt = hrtime(true);

            try {
                $result = DB::transaction($callback, 1);
                $this->flush($retries);

                return $result;
            } catch (Throwable $exception) {
                $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($exception)) {
                    $this->flush($retries);

                    throw $exception;
                }

                $retries[] = [$this->category($exception), $attempt, $elapsedMs];
            }
        }
    }

    /** @param  list<array{string, int, int}>  $retries */
    private function flush(array $retries): void
    {
        foreach ($retries as [$outcome, $attempt, $elapsedMs]) {
            $this->events->record(
                BalanceOperationMetric::LockRetry,
                $outcome,
                $this->events->durationBucket($elapsedMs),
                $attempt,
            );
        }
    }

    private function causedByConcurrencyError(Throwable $exception): bool
    {
        $container = app();

        $detector = $container->bound(ConcurrencyErrorDetectorContract::class)
            ? $container->make(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector();

        return $detector->causedByConcurrencyError($exception);
    }

    private function category(Throwable $exception): string
    {
        $code = (string) $exception->getCode();
        if ($code === '40P01' || str_contains(strtolower($exception->getMessage()), 'deadlock')) {
            return 'deadlock';
        }

        return 'concurrency';
    }
}
