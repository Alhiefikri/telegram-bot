<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AssertDatabaseQueueEmpty extends Command
{
    protected $signature = 'ops:assert-database-queue-empty';

    protected $description = 'Refuse Redis startup while database-backed jobs remain queued.';

    public function handle(): int
    {
        try {
            $count = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        } catch (Throwable) {
            $this->error('Redis cutover blocked: database queue depth could not be checked.');

            return self::FAILURE;
        }

        if ($count > 0) {
            $this->error(sprintf(
                'Redis cutover blocked: %d database queue %s.',
                $count,
                $count === 1 ? 'job remains' : 'jobs remain',
            ));

            return self::FAILURE;
        }

        $this->info('Database queue is empty.');

        return self::SUCCESS;
    }
}
