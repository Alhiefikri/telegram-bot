<?php

namespace App\Services\Operations;

use App\Enums\BalanceOperationMetric;
use App\Models\BalanceOperationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Append-only, bounded-cardinality operational metric sink for the balance
 * subsystem (#558).
 *
 * Every row is one observation of a closed `BalanceOperationMetric` outcome;
 * the optional duration/size bucket comes from a fixed label set. Recording is
 * deferred to `DB::afterCommit()` whenever a caller-owned transaction is open,
 * so a metric insert can never join, delay or poison a money transaction. A
 * failed insertion is logged as a class name only: metrics must never break a
 * money path and must never leak customer, chain or provider values.
 */
final class BalanceOperationEventRecorder
{
    /** @var list<string> */
    public const DURATION_BUCKETS = ['lt_50ms', 'lt_250ms', 'lt_1s', 'lt_5s', 'gte_5s'];

    public function record(
        BalanceOperationMetric $metric,
        string $outcome,
        ?string $bucket = null,
        ?int $value = null,
    ): void {
        if (! in_array($outcome, $metric->outcomes(), true)) {
            // A closed enum mismatch is a programming error: drop the row
            // rather than widen the label set or break the caller.
            Log::debug('balance_operation_metric_outcome_unknown', [
                'metric' => $metric->value,
            ]);

            return;
        }

        if ($bucket !== null && ! in_array($bucket, self::DURATION_BUCKETS, true)) {
            Log::debug('balance_operation_metric_bucket_unknown', [
                'metric' => $metric->value,
            ]);

            return;
        }

        $write = function () use ($metric, $outcome, $bucket, $value): void {
            try {
                BalanceOperationEvent::query()->create([
                    'metric' => $metric,
                    'outcome' => $outcome,
                    'bucket' => $bucket,
                    'value' => $value === null ? null : max(0, $value),
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('balance_operation_metric_record_failed', [
                    'metric' => $metric->value,
                    'exception' => $exception::class,
                ]);
            }
        };

        // Money seams call this while a caller-owned transaction is open (for
        // example settlement, checkout or refund). The row is therefore written
        // only after that transaction commits: a failed metric insert must
        // never poison a transaction that is moving money.
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($write);

            return;
        }

        $write();
    }

    public function durationBucket(?int $milliseconds): string
    {
        $milliseconds ??= 0;

        return match (true) {
            $milliseconds < 50 => 'lt_50ms',
            $milliseconds < 250 => 'lt_250ms',
            $milliseconds < 1000 => 'lt_1s',
            $milliseconds < 5000 => 'lt_5s',
            default => 'gte_5s',
        };
    }
}
