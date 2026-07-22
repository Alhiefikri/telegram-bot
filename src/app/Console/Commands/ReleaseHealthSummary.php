<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReleaseHealthSummary extends Command
{
    protected $signature = 'ops:release-health-summary {--since= : ISO-8601 candidate release start time}';

    protected $description = 'Print a secret-free machine-readable release health summary.';

    public function handle(): int
    {
        $since = $this->releaseStart();
        if ($this->hasReleaseStartOption() && $since === null) {
            $this->error('Release health summary: since must be a valid ISO-8601 timestamp.');

            return self::FAILURE;
        }

        try {
            $jobs = DB::table('jobs');
            $oldestCreatedAt = $jobs->min('created_at');
            $failedJobs = DB::table('failed_jobs');
            $candidateFailedJobs = $since === null ? null : (clone $failedJobs)->where('failed_at', '>=', $since->toDateTimeString())->count();

            $this->line(json_encode([
                'generated_at' => now()->utc()->toIso8601String(),
                'release_started_at' => $since?->toIso8601String(),
                'queue' => [
                    'depth' => $jobs->count(),
                    'oldest_job_age_seconds' => $oldestCreatedAt === null ? null : max(0, now()->timestamp - (int) $oldestCreatedAt),
                ],
                'failed_jobs' => [
                    'total_retained' => $failedJobs->count(),
                    'candidate_release' => $candidateFailedJobs,
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            $this->error('Release health summary: database query failed.');

            return self::FAILURE;
        }

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
}
