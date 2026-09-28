<?php

namespace App\Console\Commands;

use App\Services\Admin\ProductImageOptimizer;
use App\Services\Bsc\BscOperationalAlertNotifier;
use App\Services\Bsc\BscOperationalMetrics;
use App\Services\Bsc\BscReadiness;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\QueueDepthInspector;
use App\Services\Sakurupiah\SakurupiahOperationalMetrics;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
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
        {--image-pipeline : Check GD capabilities used by invoice rendering and product image processing.}
        {--bsc : Check enabled BSC readiness and pending verification backlog.}
        {--sakurupiah : Check enabled Sakurupiah readiness, schedule, and bounded verification backlog.}
        {--scheduler-heartbeat= : Maximum scheduler heartbeat age in seconds.}';

    protected $description = 'Check operational health for database, queue backends, supervisor-managed processes, and scheduler liveness.';

    public function handle(
        QueueDepthInspector $queueDepths,
        BscReadiness $bscReadiness,
        BscOperationalMetrics $bscMetrics,
        BscOperationalAlertNotifier $bscAlerts,
        SakurupiahProviderReadiness $sakurupiahReadiness,
        SakurupiahOperationalMetrics $sakurupiahMetrics,
        PaidInvoiceRenderer $invoiceRenderer,
        ProductImageOptimizer $imageOptimizer,
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
            $tables = ['failed_jobs', 'job_batches', 'migrations'];
            if (config('queue.default') === 'database') {
                $tables[] = 'jobs';
            }
            if (config('cache.default') === 'database') {
                $tables = [...$tables, 'cache', 'cache_locks'];
            }
            if (config('session.driver') === 'database') {
                $tables[] = 'sessions';
            }

            foreach (array_unique($tables) as $table) {
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
            $result = Process::run('supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status php-fpm laravel-update-worker:* laravel-outbound-worker:* laravel-notification-worker:* laravel-broadcast-worker:* laravel-stock-worker:* laravel-binance-verification-worker:*');

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
                foreach ($queueDepths->operationalQueues() as $queue) {
                    $snapshot = $queueDepths->runnable($queue);
                    $oldestAge = $snapshot['oldest_pending_at'] === null
                        ? 0
                        : max(0, now()->timestamp - $snapshot['oldest_pending_at']);

                    $this->info("Queue {$queue}: depth={$snapshot['depth']} oldest_age={$oldestAge}s");
                }
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('Queue depth: failed - '.$exception->getMessage());
            }
        }

        if ($this->option('image-pipeline')) {
            $failures = array_values(array_filter([
                $invoiceRenderer->capabilityFailure(),
                $imageOptimizer->capabilityFailure(),
            ], static fn (?string $failure): bool => $failure !== null));

            if ($failures === []) {
                $this->info('Image pipeline: ok');
            } else {
                $healthy = false;
                foreach ($failures as $failure) {
                    $this->error('Image pipeline: '.$failure);
                }
            }
        }

        if ($this->option('bsc')) {
            try {
                $report = $bscReadiness->check();
                $bscAlerts->notifyReadiness($report);
                if (! $report->enabled) {
                    $this->info('BSC: disabled');
                } elseif (! $report->isReady()) {
                    $healthy = false;
                    $this->error('BSC: failed - '.$report->configurationMessage());
                } else {
                    $this->info("BSC: ready active_receivers={$report->activeReceiverCount} chain={$report->rpcChainId} decimals={$report->tokenDecimals} verification=tx_hash");
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

        if ($this->option('sakurupiah')) {
            try {
                $report = $sakurupiahReadiness->report();
                if (! $report->enabled()) {
                    $this->info('Sakurupiah: disabled');
                } elseif (! $report->isReady()) {
                    $healthy = false;
                    $this->error('Sakurupiah: failed - '.$report->configurationMessage());
                } else {
                    $mustBeScheduled = app()->environment('production');
                    $schedulingReady = ! $mustBeScheduled || $sakurupiahReadiness->schedulingEnabledFor($report->environment());
                    if (! $schedulingReady) {
                        $healthy = false;
                        $this->error('Sakurupiah: enabled but reconciliation is not scheduled.');
                    } else {
                        $this->info('Sakurupiah: ready scheduling=1');
                    }
                }

                $backlog = $sakurupiahMetrics->pendingVerification();
                $this->info("Sakurupiah backlog: pending={$backlog['pending']} verification_pending={$backlog['verification_pending']} terminal_recent={$backlog['terminal_recent']} oldest_age={$backlog['oldest_age_seconds']}s");
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('Sakurupiah: failed safely - '.$exception->getMessage());
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
}
