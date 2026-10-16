<?php

namespace App\Console\Commands;

use App\Services\CustomerApi\CustomerApiUsage;
use Illuminate\Console\Command;
use Throwable;

final class FlushCustomerApiUsage extends Command
{
    protected $signature = 'customer-api:flush-usage {--limit=500 : Maximum credential/minute aggregates, capped at 1000}';

    protected $description = 'Drain bounded, lossy Customer API usage aggregates without financial authority';

    public function handle(CustomerApiUsage $usage): int
    {
        try {
            $count = $usage->flush((int) $this->option('limit'));
            $this->info("Aggregates drained: {$count}. Counts are eventual lower bounds.");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Usage aggregation unavailable; popped telemetry may be lost. No financial state was changed.');

            return self::FAILURE;
        }
    }
}
