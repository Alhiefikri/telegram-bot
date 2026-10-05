<?php

namespace App\Console\Commands;

use App\Services\MoneyReceipts\ExternalMoneyReceiptCutoverReport;
use Illuminate\Console\Command;

final class ReportExternalMoneyReceiptCutover extends Command
{
    protected $signature = 'balance:money-receipt-cutover-report
        {--json : Emit the report as JSON}
        {--require-ready : Fail when the cutover readiness gate is not satisfied}';

    protected $description = 'Report external money receipt backfill completeness, collisions and writer cutover readiness.';

    public function handle(ExternalMoneyReceiptCutoverReport $report): int
    {
        $summary = $report->summary();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Money receipt cutover ready: '.($summary['ready'] ? 'true' : 'false'));
            $this->line('USDT funding: '.$summary['usdt_funding']);
            $this->line(sprintf(
                'Registry receipts=%d claimed=%d review=%d',
                $summary['registry']['receipts'],
                $summary['registry']['claimed'],
                $summary['registry']['review'],
            ));
            $this->line(sprintf(
                'Reservations open=%d collisions=%d',
                $summary['reservations']['open'],
                $summary['reservations']['collisions'],
            ));
            foreach ($summary['reservations']['by_reason'] as $reason => $count) {
                $this->line(sprintf('RESERVATION reason=%s count=%d', $reason, $count));
            }
            foreach ($summary['sources'] as $name => $source) {
                $this->line(sprintf(
                    'SOURCE %s status=%s complete=%s ready=%s scanned=%d claimed=%d replayed=%d reserved=%d conflicts=%d reviews=%d skipped=%d open_reservations=%d',
                    $name,
                    $source['status'],
                    $source['complete'] ? 'true' : 'false',
                    $source['ready'] ? 'true' : 'false',
                    $source['scanned'],
                    $source['claimed'],
                    $source['replayed'],
                    $source['reserved'],
                    $source['conflicts'],
                    $source['reviews'],
                    $source['skipped'],
                    $source['open_reservations'],
                ));
            }
            foreach ($summary['writers'] as $name => $mode) {
                $this->line(sprintf('WRITER %s=%s', $name, $mode));
            }
            $this->line('Compatibility: '.$summary['compatibility']);
        }

        if ((bool) $this->option('require-ready') && $summary['ready'] !== true) {
            $this->error('External money receipt cutover readiness gate is not satisfied.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
