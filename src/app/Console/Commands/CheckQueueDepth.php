<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckQueueDepth extends Command
{
    protected $signature = 'ops:check-queue-depth {--max=0 : Maximum acceptable queued job count} {--queue=* : Queue name to include; repeatable. Defaults to all queues}';

    protected $description = 'Alert when queued jobs exceeds the configured threshold.';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $queues = array_values(array_filter(array_map(
            fn (mixed $queue): string => trim((string) $queue),
            (array) $this->option('queue')
        )));

        if ($max < 0) {
            $this->error('ALERT queue depth max must be zero or greater.');

            return self::FAILURE;
        }

        try {
            $query = DB::table('jobs');

            if ($queues !== []) {
                $query->whereIn('queue', $queues);
            }

            $count = $query->count();
        } catch (Throwable $e) {
            $this->error('ALERT queue depth check could not query database: '.$e->getMessage());

            return self::FAILURE;
        }

        $scope = $queues === [] ? 'all queues' : 'queues '.implode(',', $queues);

        if ($count > $max) {
            $this->error("ALERT queue depth {$count} exceeds max {$max} for {$scope}.");

            return self::FAILURE;
        }

        $this->info("OK queue depth {$count} is within max {$max} for {$scope}.");

        return self::SUCCESS;
    }
}
