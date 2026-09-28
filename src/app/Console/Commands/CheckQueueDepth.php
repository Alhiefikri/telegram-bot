<?php

namespace App\Console\Commands;

use App\Services\QueueDepthInspector;
use Illuminate\Console\Command;
use Throwable;

class CheckQueueDepth extends Command
{
    protected $signature = 'ops:check-queue-depth
        {--max=0 : Maximum acceptable runnable queued job count}
        {--max-oldest-age=0 : Maximum seconds the oldest runnable job may wait; zero requires no runnable job}
        {--queue=* : Queue name to include. Defaults to all operational queues}
        {--include-reserved-and-delayed : Include in-flight and delayed jobs in the depth check}';

    protected $description = 'Alert when runnable backlog or total queue depth exceeds the configured threshold.';

    public function handle(QueueDepthInspector $queueDepths): int
    {
        $max = (int) $this->option('max');
        $maxOldestAge = (int) $this->option('max-oldest-age');
        $queues = array_values(array_filter(array_map(
            fn (mixed $queue): string => trim((string) $queue),
            (array) $this->option('queue')
        )));

        if ($max < 0 || $maxOldestAge < 0) {
            $this->error('ALERT queue depth thresholds must be zero or greater.');

            return self::FAILURE;
        }

        if ($queues === []) {
            $queues = $queueDepths->operationalQueues();
        }

        $includeReservedAndDelayed = (bool) $this->option('include-reserved-and-delayed');

        try {
            $count = 0;
            $oldestPendingAt = null;

            foreach ($queues as $queue) {
                if ($includeReservedAndDelayed) {
                    $count += $queueDepths->total($queue);

                    continue;
                }

                $snapshot = $queueDepths->runnable($queue);
                $count += $snapshot['depth'];
                if ($snapshot['oldest_pending_at'] !== null) {
                    $oldestPendingAt = $oldestPendingAt === null
                        ? $snapshot['oldest_pending_at']
                        : min($oldestPendingAt, $snapshot['oldest_pending_at']);
                }
            }
        } catch (Throwable $e) {
            $this->error('ALERT queue depth check could not inspect queues: '.$e->getMessage());

            return self::FAILURE;
        }

        $scope = 'queues '.implode(',', $queues);
        $oldestAge = $oldestPendingAt === null ? 0 : max(0, now()->timestamp - $oldestPendingAt);

        if ($count > $max || $oldestAge > $maxOldestAge) {
            $this->error("ALERT queue depth {$count} (oldest age {$oldestAge}s) exceeds thresholds max {$max}, max oldest age {$maxOldestAge}s for {$scope}.");

            return self::FAILURE;
        }

        $this->info("OK queue depth {$count} (oldest age {$oldestAge}s) is within thresholds max {$max}, max oldest age {$maxOldestAge}s for {$scope}.");

        return self::SUCCESS;
    }
}
