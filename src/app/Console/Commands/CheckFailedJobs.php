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
            $this->error("ALERT failed_jobs count {$count} exceeds max {$max}. Recent job types: ".$this->recentFailedJobTypes());

            return self::FAILURE;
        }

        $this->info("OK failed_jobs count {$count} is within max {$max}.");

        return self::SUCCESS;
    }
    private function recentFailedJobTypes(): string
    {
        $types = [];

        foreach (DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->pluck('payload') as $payload) {
            $displayName = data_get(json_decode((string) $payload, true), 'displayName');
            $type = is_string($displayName) && preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*$/', $displayName)
                ? $displayName
                : 'unknown';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        ksort($types);

        return implode(', ', array_map(static fn (string $type): string => "{$type}={$types[$type]}", array_keys($types)));
    }
}
