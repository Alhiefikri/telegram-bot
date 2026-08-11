<?php

namespace App\Console\Commands;

use App\Services\PaymentLifecycleRollout;
use App\Services\PaymentLifecycleRolloutReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportPaymentLifecycleRollout extends Command
{
    protected $signature = 'ops:payment-lifecycle-rollout-report {--require-contract-safe : Exit unsuccessfully until every legacy row and operational queue is resolved}';

    protected $description = 'Report rollout flags, legacy reconciliation work, and lifecycle operational queues.';

    public function handle(PaymentLifecycleRollout $rollout, PaymentLifecycleRolloutReport $report): int
    {
        try {
            $summary = $report->summary($rollout);
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            if ($summary['unresolved_total'] > 0) {
                // Structured and secret-free so existing log-based alerting can
                // page on any rollout queue that stops making progress.
                Log::warning('payment_lifecycle_rollout.unresolved', [
                    'rollout' => $summary['rollout'],
                    'unresolved' => $summary['unresolved'],
                    'unresolved_total' => $summary['unresolved_total'],
                ]);
            }
        } catch (\Throwable) {
            $this->error('Payment lifecycle rollout report: database query failed.');

            return self::FAILURE;
        }

        if ((bool) $this->option('require-contract-safe') && ! $summary['contract_safe']) {
            $this->error('Payment lifecycle rollout report: contract is blocked by unresolved rows.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
