<?php

namespace Tests\Feature;

use App\Models\UsdtBalanceReconciliationRun;
use App\Services\Operations\BalanceReleaseReadinessReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BalanceReleaseReadinessReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_candidate_verification_evidence_blocks_release_evidence(): void
    {
        $report = app(BalanceReleaseReadinessReport::class)->report();
        $checks = collect($report['checks'])->keyBy('key');

        $this->assertSame('blocked', $checks['release_evidence']['status']);
        $this->assertContains('release_evidence', $report['blocking_checks']);
    }

    public function test_it_fails_closed_without_complete_receipt_and_reconciliation_evidence(): void
    {
        $this->artisan('balance:release-readiness-report', ['--require-ready' => true])
            ->expectsOutputToContain('money_receipt_registry')
            ->expectsOutputToContain('primary_bsc_activation')
            ->assertExitCode(1);
    }

    public function test_it_accepts_only_a_complete_full_reconciliation_as_reconciliation_evidence(): void
    {
        $this->recordRun([
            'findings' => [
                'accounts' => ['has_more' => true],
                'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false],
                'treasury' => ['verdict' => 'consistent'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $report = app(BalanceReleaseReadinessReport::class)->report();
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('blocked', $checks['full_reconciliation']['status']);

        $this->recordRun([
            'accounts_inspected' => 1001,
            'findings' => [
                'accounts' => ['has_more' => false],
                'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false],
                'treasury' => ['verdict' => 'consistent'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $report = app(BalanceReleaseReadinessReport::class)->report();
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('pass', $checks['full_reconciliation']['status']);
        $this->assertFalse($report['release_ready']);
        $this->assertContains('primary_bsc_activation', $report['blocking_checks']);
    }

    public function test_it_does_not_promote_a_clean_last_page_over_findings_from_an_earlier_page(): void
    {
        $this->recordRun([
            'verdict' => 'findings',
            'findings' => [
                'scope' => ['complete' => false],
                'accounts' => ['has_more' => true, 'findings' => [['account_id' => 7]]],
                'receipts' => ['receipts_has_more' => true, 'credits_have_more' => false],
                'treasury' => ['verdict' => 'consistent'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $this->recordRun([
            'findings' => [
                'scope' => ['complete' => false],
                'accounts' => ['has_more' => false, 'findings' => []],
                'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false, 'findings' => []],
                'treasury' => ['verdict' => 'consistent'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $checks = collect(app(BalanceReleaseReadinessReport::class)->report()['checks'])->keyBy('key');

        $this->assertSame('blocked', $checks['full_reconciliation']['status']);
    }

    public function test_json_report_contains_only_sanitized_gate_evidence(): void
    {
        $this->assertSame(0, Artisan::call('balance:release-readiness-report', ['--json' => true]));

        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['generated_at', 'candidate_sha', 'release_ready', 'blocking_checks', 'checks', 'next_operator_gate'],
            array_keys($report),
        );
        $this->assertNull($report['candidate_sha']);
        $this->assertNotContains('optional_binance_funding', $report['blocking_checks']);
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame(
            'unqualified',
            $checks['primary_bsc_activation']['evidence']['qualification_gates']['legal_and_customer_funds'],
        );
    }

    public function test_it_rejects_stale_reconciliation_evidence_and_invalid_age_limits(): void
    {
        $this->recordRun([
            'checked_at' => now()->subDays(2),
            'findings' => [
                'accounts' => ['has_more' => false],
                'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false],
                'treasury' => ['verdict' => 'consistent'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $checks = collect(app(BalanceReleaseReadinessReport::class)->report(60)['checks'])->keyBy('key');
        $this->assertSame('blocked', $checks['full_reconciliation']['status']);

        $this->artisan('balance:release-readiness-report', ['--max-reconciliation-age' => 0])
            ->expectsOutputToContain('must be between 1 and 10080')
            ->assertExitCode(1);
    }

    public function test_it_accepts_a_zero_liability_full_reconciliation_without_fabricating_custody_coverage(): void
    {
        $this->recordRun([
            'findings' => [
                'accounts' => ['has_more' => false],
                'receipts' => ['receipts_has_more' => false, 'credits_have_more' => false],
                'treasury' => ['verdict' => 'not_required'],
                'accounting' => ['consistent' => true],
            ],
        ]);

        $checks = collect(app(BalanceReleaseReadinessReport::class)->report()['checks'])->keyBy('key');
        $this->assertSame('pass', $checks['full_reconciliation']['status']);
    }

    /** @param array<string, mixed> $overrides */
    private function recordRun(array $overrides): void
    {
        if (isset($overrides['findings']) && is_array($overrides['findings'])) {
            $overrides['findings'] = array_replace(
                ['scope' => ['complete' => true]],
                $overrides['findings'],
            );
        }

        UsdtBalanceReconciliationRun::query()->create(array_replace([
            'scope' => 'full',
            'limit' => 1000,
            'checked_at' => now(),
            'created_at' => now(),
            'verdict' => 'consistent',
            'accounts_inspected' => 0,
            'account_finding_count' => 0,
            'receipt_finding_count' => 0,
            'aggregate_pending_events' => 0,
            'findings' => [],
        ], $overrides));
    }
}
