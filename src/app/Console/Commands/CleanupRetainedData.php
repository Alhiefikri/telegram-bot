<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CleanupRetainedData extends Command
{
    protected $signature = 'ops:cleanup-retained-data {--chunk=500} {--max-rows=5000} {--max-seconds=30}';

    protected $description = 'Prune old completed Telegram updates with shared row and time limits; retain payment and audit evidence';

    public function handle(): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $maxRows = filter_var($this->option('max-rows'), FILTER_VALIDATE_INT);
        $maxSeconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT);
        $retentionDays = filter_var(config('services.data_retention_days', 90), FILTER_VALIDATE_INT);

        if ($chunk === false || $chunk < 1 || $chunk > 1000
            || $maxRows === false || $maxRows < 1 || $maxRows > 100000
            || $maxSeconds === false || $maxSeconds < 1 || $maxSeconds > 300
            || $retentionDays === false || $retentionDays < 1 || $retentionDays > 36500) {
            $this->error('Invalid retention limits (chunk 1-1000, max-rows 1-100000, max-seconds 1-300, retention days 1-36500).');

            return self::FAILURE;
        }

        $deadline = hrtime(true) + $maxSeconds * 1_000_000_000;
        $cutoff = now()->subDays($retentionDays);
        $remainingRows = $maxRows;
        $telegram = 0;
        $connection = DB::connection();
        $inheritedTimeouts = $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() > 0
            ? $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout")
            : null;
        $query = TelegramUpdate::query()
            ->where('created_at', '<', $cutoff)
            ->where('processed_at', '<', $cutoff)
            ->whereNull('processing_started_at')
            ->whereNull('next_retry_at')
            ->whereNull('terminal_failed_at');

        // Payment events own provider replay keys, create/cancel observations and
        // manual-review evidence. processed_at proves neither closure nor archival.
        // Audit logs also carry money, credential-access and review evidence.
        // Neither table has a proven closure/archive contract: retain every row.
        $this->info('Retaining all payment_events and audit_logs: lifecycle closure and archived replay/review evidence are unproven.');

        try {
            $this->deleteInChunks($query, $connection, $chunk, $remainingRows, $deadline, $telegram);
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $this->error("Retention cleanup stopped on database error (SQLSTATE {$state}); committed batches remain bounded.");
            $this->info("Deleted retained data: telegram_updates={$telegram} payment_events=0 audit_logs=0.");

            return self::FAILURE;
        } finally {
            // A savepoint does not reset SET LOCAL on successful nested batches.
            if ($inheritedTimeouts !== null) {
                $connection->selectOne("SELECT set_config('statement_timeout', ?, true), set_config('lock_timeout', ?, true)", [
                    $inheritedTimeouts->statement_timeout, $inheritedTimeouts->lock_timeout,
                ]);
            }
        }

        if ($remainingRows <= 0) {
            $this->warn('Retention cleanup stopped at the row budget; eligible rows may remain.');
        } elseif ($this->remainingMilliseconds($deadline) < 1) {
            $this->warn('Retention cleanup stopped at the time budget; eligible rows may remain.');
        }

        $this->info("Deleted retained data: telegram_updates={$telegram} payment_events=0 audit_logs=0.");

        return self::SUCCESS;
    }

    private function deleteInChunks(
        Builder $query,
        Connection $connection,
        int $chunk,
        int &$remainingRows,
        int $deadline,
        int &$deleted,
    ): void {
        while ($remainingRows > 0 && $this->remainingMilliseconds($deadline) > 0) {
            $batch = $connection->transaction(function () use ($query, $connection, $chunk, &$remainingRows, $deadline): ?int {
                if (! $this->limitStatement($connection, $deadline)) {
                    return null;
                }

                $ids = (clone $query)->orderBy('id')->limit(min($chunk, $remainingRows))->pluck('id');
                if ($ids->isEmpty()) {
                    return null;
                }

                // Charge candidates even if concurrent changes make them ineligible.
                $remainingRows -= $ids->count();
                if (! $this->limitStatement($connection, $deadline)) {
                    return null;
                }

                return (clone $query)->whereKey($ids)->delete();
            }, 1);

            if ($batch === null) {
                break;
            }
            $deleted += $batch;
        }
    }

    private function limitStatement(Connection $connection, int $deadline): bool
    {
        $remaining = $this->remainingMilliseconds($deadline);
        if ($remaining < 1) {
            return false;
        }

        if ($connection->getDriverName() === 'pgsql') {
            // PostgreSQL interprets zero as unlimited. Floor and recheck the
            // monotonic remainder before every SELECT/DELETE, never round to zero.
            $connection->statement('SET LOCAL statement_timeout = '.min(1000, $remaining));
            $remaining = $this->remainingMilliseconds($deadline);
            if ($remaining < 1) {
                return false;
            }
            $connection->statement('SET LOCAL lock_timeout = '.min(100, $remaining));
        }

        return $this->remainingMilliseconds($deadline) > 0;
    }

    private function remainingMilliseconds(int $deadline): int
    {
        return max(0, intdiv($deadline - hrtime(true), 1_000_000));
    }
}
