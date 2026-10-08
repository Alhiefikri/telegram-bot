<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceReleaseReadinessReport;
use Illuminate\Console\Command;

/**
 * This command is intentionally read-only. It records no approval and never
 * changes a funding policy, pause, receiver, ledger, receipt, or deployment.
 */
final class ReportBalanceReleaseReadiness extends Command
{
    protected $signature = 'balance:release-readiness-report
        {--json : Emit the complete secret-free report as JSON}
        {--max-reconciliation-age=1440 : Maximum age in minutes for full reconciliation evidence (1-10080)}
        {--candidate-sha= : Exact 40-character candidate commit SHA}
        {--evidence-manifest= : Path to the candidate verification result index}
        {--require-ready : Exit non-zero unless every required release check passes}';

    protected $description = 'Report IDR/Customer USDT release readiness and fail closed on missing evidence.';

    public function handle(BalanceReleaseReadinessReport $reporter): int
    {
        $maxAge = (int) $this->option('max-reconciliation-age');
        if ($maxAge < 1 || $maxAge > 10_080) {
            $this->error('The --max-reconciliation-age option must be between 1 and 10080 minutes.');

            return self::FAILURE;
        }

        $report = $reporter->report(
            $maxAge,
            $this->option('candidate-sha'),
            $this->option('evidence-manifest'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Balance release ready: '.($report['release_ready'] ? 'true' : 'false'));
            $this->line('Candidate SHA: '.($report['candidate_sha'] ?? 'missing or invalid'));
            $releaseEvidence = collect($report['checks'])->firstWhere('key', 'release_evidence')['evidence'];
            $this->line('Evidence manifest SHA-256: '.($releaseEvidence['manifest_sha256'] ?? 'missing'));
            foreach ($releaseEvidence['results'] as $key => $result) {
                $this->line(sprintf('Evidence %s: %s — %s', $key, $result['reason'], $result['reference'] ?? 'missing artifact'));
            }
            foreach ($report['checks'] as $check) {
                $this->line(sprintf('%s %s — %s', strtoupper($check['status']), $check['key'], $check['summary']));
            }
            if ($report['blocking_checks'] !== []) {
                $this->warn('NO-GO checks: '.implode(', ', $report['blocking_checks']));
            }
        }

        if ((bool) $this->option('require-ready') && $report['release_ready'] !== true) {
            $this->error('Balance release readiness gate is not satisfied. No deployment or activation was attempted.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
