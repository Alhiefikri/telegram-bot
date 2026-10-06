<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceOperationMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Observability surface for the balance subsystem (#558): prints the bounded
 * operations report and optionally prunes aged metric events. It is read-only
 * over money state and never writes a balance, entry, receipt or effect; the
 * only mutation is the bounded, chunked retention delete of its own metric
 * events.
 */
final class ReportBalanceMetrics extends Command
{
    private const MAX_PRUNE_CHUNK = 5000;

    protected $signature = 'usdt-balance:metrics
        {--window-minutes=60 : Metrics window in minutes (1-1440).}
        {--prune-days= : Delete metric events older than N days (bounded, chunked).}
        {--json : Emit the report as JSON}';

    protected $description = 'Report bounded balance operation counters, backlogs, drift, coverage and conflicts.';

    public function handle(BalanceOperationMetrics $metrics): int
    {
        $windowMinutes = min(1440, max(1, (int) $this->option('window-minutes')));

        $pruneDays = trim((string) ($this->option('prune-days') ?? ''));
        $pruned = $pruneDays === '' ? null : $this->prune((int) $pruneDays);

        $report = $metrics->report($windowMinutes);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($report);
            if ($pruned !== null) {
                $this->line(sprintf('Pruned %d metric event(s) older than %d day(s).', $pruned, (int) $pruneDays));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Delete aged metric events in bounded chunks so retention never issues an
     * unbounded delete. Returns the number of removed rows.
     */
    private function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        while (true) {
            $ids = DB::table('balance_operation_events')
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::MAX_PRUNE_CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('balance_operation_events')->whereIn('id', $ids->all())->delete();
        }

        return $deleted;
    }

    /** @param  array<string, mixed>  $report */
    private function renderText(array $report): void
    {
        $this->line(sprintf(
            'Balance operations metrics (window=%dm, generated=%s)',
            $report['window_minutes'],
            $report['generated_at'],
        ));

        foreach ($report['counters'] as $metric => $outcomes) {
            $parts = [];
            foreach ($outcomes as $outcome => $count) {
                $parts[] = sprintf('%s=%d', $outcome, $count);
            }
            $this->line(sprintf('COUNTER %s %s', $metric, implode(' ', $parts)));
        }

        foreach ($report['backlog'] as $name => $facet) {
            $counts = [];
            foreach ($facet as $key => $value) {
                $counts[] = $key === 'oldest_age_seconds'
                    ? sprintf('oldest=%ds', $value)
                    : sprintf('%s=%d', $key, $value);
            }
            $this->line(sprintf('BACKLOG %s %s', $name, implode(' ', $counts)));
        }

        $drift = $report['drift'];
        $latest = $drift['latest'];
        $this->line(sprintf(
            'DRIFT frozen_accounts=%d latest=%s',
            $drift['frozen_accounts'],
            $latest === null
                ? 'none'
                : sprintf('verdict=%s checked_at=%s account_findings=%d receipt_findings=%d drift_frozen=%d custody_state=%s',
                    $latest['verdict'],
                    $latest['checked_at'],
                    $latest['account_findings'],
                    $latest['receipt_findings'],
                    $latest['drift_frozen'],
                    $latest['custody_state'] ?? 'none',
                ),
        ));

        $coverage = $report['coverage'];
        $this->line(sprintf(
            'COVERAGE ledger_liability_micro=%s unresolved_deposit_micro=%s custody_state=%s custody_assets_micro=%s verdict=%s',
            $coverage['ledger_liability_micro'],
            $coverage['unresolved_deposit_micro'],
            $coverage['custody_state'] ?? 'none',
            $coverage['custody_assets_micro'] ?? 'none',
            $coverage['verdict'] ?? 'none',
        ));

        $conflict = $report['conflict'];
        $this->line(sprintf(
            'CONFLICT review_receipts=%d review_parent_claims=%d open_registry_conflict_reservations=%d',
            $conflict['review_receipts'],
            $conflict['review_parent_claims'],
            $conflict['open_registry_conflict_reservations'],
        ));

        $pauses = $report['controls']['pauses'];
        $gate = $report['controls']['restore_gate'];
        $this->line(sprintf(
            'CONTROLS pauses=%s restore_gate=%s opened_at=%s window=%s..%s replay_completed_at=%s',
            $pauses === [] ? 'none' : implode(',', $pauses),
            $gate['state'],
            $gate['opened_at'] ?? 'none',
            $gate['window_start'] ?? 'none',
            $gate['window_end'] ?? 'none',
            $gate['replay_completed_at'] ?? 'none',
        ));
    }
}
