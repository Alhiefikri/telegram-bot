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
        {--prune-unrecoverable : Delete Telegram update failed_jobs that still fail after one direct retry}';

    protected $description = 'Retry failed Telegram update jobs directly and remove only records that recover successfully.';

    public function handle(TelegramUpdateProcessor $processor): int
    {
        $recovered = 0;
        $pruned = 0;
        $failed = 0;
        $failedPruned = 0;
        $dryRun = (bool) $this->option('dry-run');
        $pruneUnrecoverable = (bool) $this->option('prune-unrecoverable');

        foreach (DB::table('failed_jobs')->orderBy('failed_at')->get(['uuid', 'payload']) as $failedJob) {
            $job = $this->processTelegramUpdateJob((string) $failedJob->payload);
            if ($job === null) {
                continue;
            }

            $update = TelegramUpdate::query()->find($job->telegramUpdateId);
            if ($update === null) {
                if (! $dryRun) {
                    DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                }

                $pruned++;

                continue;
            }

            if ($dryRun) {
                $recovered++;

                continue;
            }

            try {
                $job->handle($processor);
                DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                $recovered++;
            } catch (Throwable $exception) {
                report($exception);
                if ($pruneUnrecoverable) {
                    DB::table('failed_jobs')->where('uuid', $failedJob->uuid)->delete();
                    $failedPruned++;

                    continue;
                }

                $failed++;
            }
        }

        $this->info("telegram:recover-failed-updates completed: recovered={$recovered} pruned={$pruned} failed_pruned={$failedPruned} failed={$failed}");

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
