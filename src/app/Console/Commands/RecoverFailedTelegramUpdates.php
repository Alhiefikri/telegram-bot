<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverFailedTelegramUpdates extends Command
{
    protected $signature = 'telegram:recover-failed-updates {--dry-run : Report recoverable failed Telegram update jobs without processing them}';

    protected $description = 'Retry failed Telegram update jobs directly and remove only records that recover successfully.';

    public function handle(TelegramUpdateProcessor $processor): int
    {
        $recovered = 0;
        $failed = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach (DB::table('failed_jobs')->orderBy('failed_at')->get(['uuid', 'payload']) as $failedJob) {
            $job = $this->processTelegramUpdateJob((string) $failedJob->payload);
            if ($job === null) {
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
                $failed++;
            }
        }

        $this->info("telegram:recover-failed-updates completed: recovered={$recovered} failed={$failed}");

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
