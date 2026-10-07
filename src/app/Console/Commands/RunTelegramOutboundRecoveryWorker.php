<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceOperationsAlertService;
use App\Services\Telegram\TelegramOutboundRecoveryProcessRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class RunTelegramOutboundRecoveryWorker extends Command
{
    private const INTERVAL_SECONDS = 60;

    private const PROCESS_MAX_SECONDS = 45;

    protected $signature = 'telegram:recover-outbound-worker {--once : Run one bounded recovery sweep and exit.}';

    protected $description = 'Run bounded Telegram outbound recovery in its dedicated runtime container.';

    public function handle(
        TelegramOutboundRecoveryProcessRunner $runner,
        BalanceOperationsAlertService $alerts,
    ): int {
        $heartbeatPath = storage_path('framework/telegram-outbound-recovery-heartbeat');
        $runningPath = storage_path('framework/telegram-outbound-recovery-running');

        if (is_file($runningPath)) {
            $alerts->reconcileSchedulerRuntime('outbound-recovery', healthy: false, reason: 'recovery_interrupted');
            File::delete($runningPath);
        }

        do {
            $startedAt = hrtime(true);
            File::put($runningPath, now()->toIso8601String());

            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'telegram:recover-outbound',
                '--chunk=25',
                '--queue=notifications',
                '--max-items=25',
                '--max-seconds=35',
            ], base_path());
            $exitCode = $runner->run($process, self::PROCESS_MAX_SECONDS, 'telegram:recover-outbound');

            if ($exitCode === 0) {
                $alerts->reconcileSchedulerRuntime('outbound-recovery', healthy: true);
                File::put($heartbeatPath, now()->toIso8601String());
                File::delete($runningPath);
            } else {
                $reason = $exitCode === 124 ? 'recovery_timeout' : 'recovery_exit';
                $alerts->reconcileSchedulerRuntime('outbound-recovery', healthy: false, reason: $reason);
                File::delete($runningPath);
                $this->warn("telegram:recover-outbound exited with status {$exitCode}; runtime alert recorded.");
            }

            if ($this->option('once')) {
                return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
            }

            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            sleep(max(1, self::INTERVAL_SECONDS - (int) ceil($elapsedSeconds)));
        } while (true);
    }
}
