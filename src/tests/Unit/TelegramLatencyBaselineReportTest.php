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
                'receipt_retry_attempts' => $index === 0 ? 1 : 0,
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
        $this->assertNull($queueWait['p95_ms']);
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

    public function test_p95_requires_twenty_known_observations(): void
    {
        $report = new LatencyBaselineReport;
        $samples = array_map(static fn (int $n): array => ['queue_wait_ms' => $n], range(1, 20));
        $small = $report->summarize(array_slice($samples, 0, 19));
        $enough = $report->summarize([...$samples, ['queue_wait_ms' => null]]);
        $this->assertNull($small['boundaries']['queue_wait_ms']['p95_ms']);
        $this->assertSame(19.0, $enough['boundaries']['queue_wait_ms']['p95_ms']);
        $this->assertSame(1, $enough['boundaries']['queue_wait_ms']['unknown']);
    }

    public function test_successor_pickups_are_not_counted_as_retries(): void
    {
        $summary = (new LatencyBaselineReport)->summarize([
            ['receipt_pickups' => 3, 'receipt_retry_attempts' => 0],
            ['receipt_pickups' => 2, 'receipt_retry_attempts' => 1],
        ]);
        $this->assertSame(1, $summary['counts']['retried_samples']);
    }
}
