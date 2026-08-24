<?php

namespace App\Console\Commands;

use App\Services\Bsc\BscOperationalAlertNotifier;
use App\Services\Bsc\BscOperationalMetrics;
use App\Services\Bsc\BscReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthCheck extends Command
{
    protected $signature = 'ops:health
        {--db}
        {--queue-schema}
        {--queue-depth : Report queued job count and oldest queued age for operational queues.}
        {--supervisor}
        {--bsc : Check enabled BSC readiness and pending verification backlog.}
        {--scheduler-heartbeat= : Maximum scheduler heartbeat age in seconds.}';

    protected $description = 'Check operational health for database, queue schema, supervisor-managed processes, and scheduler liveness.';

    public function handle(
        BscReadiness $bscReadiness,
        BscOperationalMetrics $bscMetrics,
        BscOperationalAlertNotifier $bscAlerts,
    ): int {
        $healthy = true;

        if ($this->option('db')) {
            try {
                DB::select('select 1');
                $this->info('DB: ok');
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('DB: failed - '.$exception->getMessage());
            }
        }

        if ($this->option('queue-schema')) {
            foreach (['cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'migrations', 'sessions'] as $table) {
                if (! Schema::hasTable($table)) {
                    $healthy = false;
                    $this->error("Schema: missing {$table}");
                }
            }

            if ($healthy) {
                $this->info('Queue schema: ok');
            }
        }

        if ($this->option('supervisor')) {
            $result = Process::run('supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status php-fpm laravel-update-worker:* laravel-outbound-worker:* laravel-notification-worker:* laravel-broadcast-worker:* laravel-binance-verification-worker:*');

            if (! $result->successful() || str_contains($result->output().$result->errorOutput(), 'FATAL') || str_contains($result->output(), 'STOPPED') || str_contains($result->output(), 'EXITED') || ! str_contains($result->output(), 'RUNNING')) {
                $healthy = false;
                $this->error('Supervisor: failed');
                $this->line(trim($result->output().$result->errorOutput()));
            } else {
                $this->info('Supervisor: ok');
            }
        }

        if ($this->option('queue-depth')) {
            try {
                foreach ($this->operationalQueues() as $queue) {
                    $query = DB::table('jobs')->where('queue', $queue);
                    $count = (int) $query->count();
                    $oldestCreatedAt = $count > 0 ? $query->min('created_at') : null;
                    $oldestAge = $oldestCreatedAt === null ? 0 : max(0, now()->timestamp - (int) $oldestCreatedAt);

                    $this->info("Queue {$queue}: depth={$count} oldest_age={$oldestAge}s");
                }
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('Queue depth: failed - '.$exception->getMessage());
            }
        }

        if ($this->option('bsc')) {
            try {
                $report = $bscReadiness->check();
                if (! $report->enabled) {
                    $this->info('BSC: disabled');
                } elseif (! $report->isReady()) {
                    $healthy = false;
                    $this->error('BSC: failed - '.$report->configurationMessage());
                    $bscAlerts->notifyReadiness($report);
                } else {
                    $this->info("BSC: ready active_receivers={$report->activeReceiverCount} chain={$report->rpcChainId} decimals={$report->tokenDecimals}");
                }

                $backlog = $bscMetrics->pendingVerification();
                $threshold = max(1, (int) config('services.bsc.pending_backlog_alert_threshold', 50));
                $this->info("BSC backlog: pending={$backlog['pending']} oldest_age={$backlog['oldest_age_seconds']}s threshold={$threshold}");
                if ($backlog['pending'] > $threshold) {
                    $healthy = false;
                    $this->error('BSC backlog exceeds the configured alert threshold.');
                    $bscAlerts->notifyBacklog($backlog['pending'], $threshold, $backlog['oldest_age_seconds']);
                }
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('BSC: failed safely - '.$exception->getMessage());
            }
        }

        if ($this->option('scheduler-heartbeat') !== null) {
            $maxAgeSeconds = (int) $this->option('scheduler-heartbeat');
            $heartbeatPath = storage_path('framework/scheduler-heartbeat');

            if ($maxAgeSeconds <= 0) {
                $healthy = false;
                $this->error('Scheduler heartbeat: max age must be greater than 0 seconds');
            } elseif (! is_file($heartbeatPath)) {
                $healthy = false;
                $this->error('Scheduler heartbeat: missing');
            } else {
                $ageSeconds = time() - filemtime($heartbeatPath);

                if ($ageSeconds > $maxAgeSeconds) {
                    $healthy = false;
                    $this->error("Scheduler heartbeat: stale ({$ageSeconds}s old, max {$maxAgeSeconds}s)");
                } else {
                    $this->info("Scheduler heartbeat: ok ({$ageSeconds}s old)");
                }
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function operationalQueues(): array
    {
        return ['default', 'telegram-outbound', 'notifications', 'telegram-broadcast', (string) config('services.binance.verification_queue', 'binance-verification')];
    }
}
