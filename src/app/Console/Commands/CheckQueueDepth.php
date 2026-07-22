<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckQueueDepth extends Command
{
    protected $signature = 'ops:check-queue-depth
        {--max=0 : Maximum acceptable queued job count}
        {--max-oldest-age=0 : Maximum age in seconds for the oldest queued job; zero requires an empty queue}
        {--queue=* : Queue name to include; repeatable. Defaults to all queues}';

    protected $description = 'Alert when queued jobs exceeds the configured threshold.';

    public function handle(): int
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

        try {
            $query = DB::table('jobs');

            if ($queues !== []) {
                $query->whereIn('queue', $queues);
            }

            $count = $query->count();
            $oldestCreatedAt = $count > 0 ? $query->min('created_at') : null;
        } catch (Throwable $e) {
            $this->error('ALERT queue depth check could not query database: '.$e->getMessage());

            return self::FAILURE;
        }

        $scope = $queues === [] ? 'all queues' : 'queues '.implode(',', $queues);

        $oldestAge = $oldestCreatedAt === null ? 0 : max(0, now()->timestamp - (int) $oldestCreatedAt);

        if ($count > $max || $oldestAge > $maxOldestAge) {
            $this->error("ALERT queue depth {$count} (oldest age {$oldestAge}s) exceeds thresholds max {$max}, max oldest age {$maxOldestAge}s for {$scope}.");

            return self::FAILURE;
        }

        $this->info("OK queue depth {$count} (oldest age {$oldestAge}s) is within thresholds max {$max}, max oldest age {$maxOldestAge}s for {$scope}.");

        return self::SUCCESS;
    }
}
