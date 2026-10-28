<?php

namespace Tests\Feature;

use App\Support\QueueTopology;
use Tests\TestCase;

final class QueueConsumerTopologyTest extends TestCase
{
    public function test_every_operational_queue_has_a_supervised_consumer(): void
    {
        $programs = $this->supervisorPrograms();
        $consumed = array_merge(...array_values(array_map(static fn (array $worker): array => $worker['queues'], $programs)));

        foreach (QueueTopology::operational() as $queue) {
            $this->assertContains($queue, $consumed, "Queue {$queue} has no supervised consumer.");
        }
    }

    public function test_receipt_work_has_a_dedicated_worker(): void
    {
        $programs = $this->supervisorPrograms();
        $receiptWorkers = array_keys(array_filter(
            $programs,
            static fn (array $worker): bool => in_array(QueueTopology::RECEIPTS, $worker['queues'], true),
        ));

        $this->assertSame(['laravel-receipt-worker'], $receiptWorkers);

        foreach ($programs as $name => $worker) {
            if ($name === 'laravel-receipt-worker') {
                continue;
            }

            $this->assertNotContains(
                QueueTopology::RECEIPTS,
                $worker['queues'],
                "Receipt work must not share {$name}.",
            );
        }
    }

    public function test_payment_verification_does_not_share_the_interaction_worker(): void
    {
        foreach ($this->supervisorPrograms() as $name => $worker) {
            $this->assertFalse(
                in_array(QueueTopology::PAYMENTS, $worker['queues'], true)
                    && in_array(QueueTopology::INTERACTIONS, $worker['queues'], true),
                "Payment verification must not share {$name} with Telegram interactions.",
            );
        }
    }

    public function test_worker_timeouts_stay_below_the_redis_retry_after(): void
    {
        $compose = (string) file_get_contents(base_path('../docker-compose.yml'));
        $this->assertSame(
            1,
            preg_match('/REDIS_QUEUE_RETRY_AFTER:\s*(\d+)/', $compose, $match),
            'The compose queue retry_after must be explicit.',
        );
        $retryAfter = (int) $match[1];

        foreach ($this->supervisorPrograms() as $name => $worker) {
            $this->assertSame(1, preg_match('/--timeout=(\d+)/', $worker['command'], $timeout), "{$name} has no timeout.");
            $this->assertSame(1, preg_match('/--tries=(\d+)/', $worker['command'], $tries), "{$name} has no tries.");

            $this->assertLessThan($retryAfter, (int) $timeout[1], "{$name} timeout must stay below retry_after.");
            $this->assertGreaterThanOrEqual(1, (int) $tries[1], "{$name} must retry a failed job.");
        }
    }

    /** @return array<string, array{queues: list<string>, command: string}> */
    private function supervisorPrograms(): array
    {
        $contents = (string) file_get_contents(base_path('../supervisord.conf'));

        return QueueTopology::supervisorPrograms($contents);
    }
}
