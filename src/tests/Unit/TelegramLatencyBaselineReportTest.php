<?php

namespace Tests\Unit;

use App\Services\Telegram\LatencyBaseline\LatencyBaselineReport;
use PHPUnit\Framework\TestCase;

class TelegramLatencyBaselineReportTest extends TestCase
{
    public function test_percentiles_use_nearest_rank_and_keep_unknown_timestamps_out(): void
    {
        $report = new LatencyBaselineReport;
        $samples = [];

        foreach ([10.0, 20.0, 30.0, 40.0, 100.0] as $index => $queueWait) {
            $samples[] = [
                'complete' => true,
                'queue_wait_ms' => $queueWait,
                'receipt_pickups' => $index === 0 ? 2 : 1,
                'duplicate_acknowledgements' => $index === 1 ? 1 : 0,
                'failed_jobs' => $index === 2 ? 1 : 0,
                'effect_states' => ['receipt.credentials@sent' => 1],
            ];
        }

        $samples[] = [
            'complete' => false,
            'queue_wait_ms' => null,
            'receipt_pickups' => 1,
            'duplicate_acknowledgements' => 0,
            'failed_jobs' => 0,
            'effect_states' => [],
        ];

        $summary = $report->summarize($samples);
        $queueWait = $summary['boundaries']['queue_wait_ms'];

        $this->assertSame(5, $queueWait['samples']);
        $this->assertSame(1, $queueWait['unknown']);
        $this->assertSame(30.0, $queueWait['p50_ms']);
        $this->assertSame(100.0, $queueWait['p95_ms']);
        $this->assertSame(100.0, $queueWait['max_ms']);
        $this->assertSame(6, $summary['sample_count']);
        $this->assertSame(5, $summary['counts']['complete']);
        $this->assertSame(1, $summary['counts']['incomplete']);
        $this->assertSame(1, $summary['counts']['queue_wait_unknown']);
        $this->assertSame(1, $summary['counts']['failed_jobs']);
        $this->assertSame(1, $summary['counts']['retried_samples']);
        $this->assertSame(1, $summary['counts']['duplicate_samples']);
        $this->assertSame(['receipt.credentials@sent' => 5], $summary['effect_states']);
    }

    public function test_missing_boundary_is_reported_as_unknown_not_zero(): void
    {
        $summary = (new LatencyBaselineReport)->summarize([[
            'complete' => false,
            'queue_wait_ms' => null,
        ]]);

        $this->assertNull($summary['boundaries']['queue_wait_ms']['p50_ms']);
        $this->assertNull($summary['boundaries']['queue_wait_ms']['p95_ms']);
        $this->assertNull($summary['boundaries']['queue_wait_ms']['max_ms']);
        $this->assertSame(1, $summary['boundaries']['queue_wait_ms']['unknown']);
    }

    public function test_markdown_states_contract_limits_and_does_not_claim_a_production_slo(): void
    {
        $report = new LatencyBaselineReport;
        $summary = $report->summarize([['complete' => true, 'queue_wait_ms' => 25]]);
        $markdown = $report->markdown([
            'run_id' => 'run-1',
            'generated_at' => '2026-10-22T00:00:00Z',
            'contract' => [
                'note' => 'Local baseline; not a production SLO.',
                'boundaries' => LatencyBaselineReport::BOUNDARIES,
            ],
            'environment' => [
                'php_version' => '8.4',
                'laravel_version' => '13',
                'database' => 'pgsql 16',
                'redis' => 'redis 7',
            ],
            'scenarios' => [
                'idle' => [
                    'dataset' => ['samples_per_batch' => 3],
                    'worker' => ['sleep_seconds' => 3],
                    'fixture' => ['telegram_latency_ms' => 0],
                    'cold' => $summary,
                    'warm' => $summary,
                ],
            ],
        ]);

        $this->assertStringContainsString('not a production SLO', $markdown);
        $this->assertStringContainsString('Telegram Bot API acknowledgement is not proof that the customer read', $markdown);
        $this->assertStringContainsString('| `queue_wait_ms` | 1 | 25.00 | 25.00 | 25.00 | 0 |', $markdown);
    }
}
