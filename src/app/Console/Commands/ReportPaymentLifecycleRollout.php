<?php

namespace App\Console\Commands;

use App\Services\PaymentLifecycleRollout;
use App\Services\PaymentLifecycleRolloutReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportPaymentLifecycleRollout extends Command
{
    protected $signature = 'ops:payment-lifecycle-rollout-report
        {--exact-legacy-stock : Run an unscheduled full-history legacy-stock count instead of bounded observation}
        {--require-contract-safe : Exit unsuccessfully on unresolved rows or incomplete legacy-stock coverage}';

    protected $description = 'Report rollout flags, legacy reconciliation work, and lifecycle operational queues.';

    public function handle(PaymentLifecycleRollout $rollout, PaymentLifecycleRolloutReport $report): int
    {
        try {
            $summary = $report->summary($rollout, exactLegacyStock: (bool) $this->option('exact-legacy-stock'));
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            if (! $summary['contract_safe']) {
                Log::warning('payment_lifecycle_rollout.unresolved', [
                    'rollout' => $summary['rollout'],
                    'unresolved' => $summary['unresolved'],
                    'unresolved_total' => $summary['unresolved_total'],
                    'unresolved_total_is_exact' => $summary['unresolved_total_is_exact'],
                    'legacy_stock_coverage' => $summary['legacy_stock_coverage'],
                ]);
            }
        } catch (\Throwable) {
            $this->error('Payment lifecycle rollout report: database query failed.');

            return self::FAILURE;
        }

        if ((bool) $this->option('require-contract-safe') && ! $summary['contract_safe']) {
            $this->error('Payment lifecycle rollout report: contract is blocked by unresolved rows or incomplete legacy-stock coverage.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
