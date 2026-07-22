<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Throwable;

class CheckFailedJobs extends Command
{
    protected $signature = 'ops:check-failed-jobs
        {--max=0 : Maximum acceptable failed job count}
        {--since= : Only count failures at or after this ISO-8601 release start time}';

    protected $description = 'Alert when failed_jobs exceeds the configured threshold.';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $since = $this->releaseStart();

        if ($max < 0) {
            $this->error('ALERT failed_jobs max must be zero or greater.');

            return self::FAILURE;
        }

        if ($this->hasReleaseStartOption() && $since === null) {
            $this->error('ALERT failed_jobs since must be a valid ISO-8601 timestamp.');

            return self::FAILURE;
        }

        try {
            $query = DB::table('failed_jobs');
            if ($since !== null) {
                $query->where('failed_at', '>=', $since->toDateTimeString());
            }
            $count = $query->count();
        } catch (Throwable $e) {
            $this->error('ALERT failed_jobs check could not query database: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($count > $max) {
            $scope = $since === null ? 'all retained failures' : 'candidate-release failures since '.$since->toIso8601String();
            $this->error("ALERT failed_jobs count {$count} exceeds max {$max} for {$scope}. Recent job types: ".$this->recentFailedJobTypes($since));

            return self::FAILURE;
        }

        $scope = $since === null ? 'all retained failures' : 'candidate-release failures since '.$since->toIso8601String();
        $this->info("OK failed_jobs count {$count} is within max {$max} for {$scope}.");

        return self::SUCCESS;
    }
    private function releaseStart(): ?Carbon
    {
        $value = $this->option('since');
        if ($value === null || is_array($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function hasReleaseStartOption(): bool
    {
        $value = $this->option('since');

        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private function recentFailedJobTypes(?Carbon $since = null): string
    {
        $types = [];

        $query = DB::table('failed_jobs')->orderByDesc('failed_at');
        if ($since !== null) {
            $query->where('failed_at', '>=', $since->toDateTimeString());
        }

        foreach ($query->limit(20)->pluck('payload') as $payload) {
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
