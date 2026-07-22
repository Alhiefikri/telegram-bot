<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverFailedTelegramUpdates extends Command
{
    protected $signature = 'telegram:recover-failed-updates
        {--dry-run : Report recoverable failed Telegram update jobs without processing them}
        {--prune-unrecoverable : Mark retry failures terminal and remove only their Telegram update failed_jobs}';

    protected $description = 'Classify and directly recover only failed Telegram update jobs.';

    public function handle(TelegramUpdateProcessor $processor): int
    {
        $supported = 0;
        $retryable = 0;
        $recovered = 0;
        $stale = 0;
        $terminal = 0;
        $failed = 0;
        $failedPruned = 0;
        $unsupported = 0;
        $dryRun = (bool) $this->option('dry-run');
        $pruneUnrecoverable = (bool) $this->option('prune-unrecoverable');

        foreach (DB::table('failed_jobs')->orderBy('failed_at')->get(['uuid', 'payload']) as $failedJob) {
            $job = $this->processTelegramUpdateJob((string) $failedJob->payload);
            if ($job === null) {
                $unsupported++;

                continue;
            }

            $supported++;

            $update = TelegramUpdate::query()->find($job->telegramUpdateId);
            if ($update === null) {
                if (! $dryRun) {
                    DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                }

                $stale++;

                continue;
            }

            if ($update->terminal_failed_at !== null) {
                if (! $dryRun) {
                    DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                }

                $terminal++;

                continue;
            }

            if ($dryRun) {
                $retryable++;

                continue;
            }

            $retryable++;

            try {
                $job->handle($processor);
                DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                $recovered++;
            } catch (Throwable $exception) {
                report($exception);
                if ($pruneUnrecoverable) {
                    $update->refresh();
                    $update->update([
                        'processing_started_at' => null,
                        'next_retry_at' => null,
                        'terminal_failed_at' => now(),
                        'last_error_class' => class_basename($exception),
                        'last_failed_at' => now(),
                    ]);
                    DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                    $failedPruned++;

                    continue;
                }

                $failed++;
            }
        }

        $mode = $dryRun ? 'dry-run' : 'completed';
        $this->info("telegram:recover-failed-updates {$mode}: supported={$supported} retryable={$retryable} recovered={$recovered} stale={$stale} terminal={$terminal} failed_pruned={$failedPruned} unsupported={$unsupported} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function processTelegramUpdateJob(string $payload): ?ProcessTelegramUpdate
    {
        $command = data_get(json_decode($payload, true), 'data.command');
        if (! is_string($command)) {
            return null;
        }

        $job = unserialize($command, ['allowed_classes' => [ProcessTelegramUpdate::class]]);

        return $job instanceof ProcessTelegramUpdate ? $job : null;
    }
}
