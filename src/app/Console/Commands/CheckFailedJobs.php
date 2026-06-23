<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckFailedJobs extends Command
{
    protected $signature = 'ops:check-failed-jobs {--max=0 : Maximum acceptable failed job count}';

    protected $description = 'Alert when failed_jobs exceeds the configured threshold.';

    public function handle(): int
    {
        $max = (int) $this->option('max');

        try {
            $count = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->error('ALERT failed_jobs check could not query database: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($count > $max) {
            $this->error("ALERT failed_jobs count {$count} exceeds max {$max}.");

            return self::FAILURE;
        }

        $this->info("OK failed_jobs count {$count} is within max {$max}.");

        return self::SUCCESS;
    }
}
