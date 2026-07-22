<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReleaseHealthSummary extends Command
{
    protected $signature = 'ops:release-health-summary
        {--baseline-stdin : Attribute candidate failures against a JSON maximum-ID baseline read from standard input}';

    protected $description = 'Print a secret-free machine-readable release health summary.';

    public function handle(): int
    {
        try {
            $jobs = DB::table('jobs');
            $oldestCreatedAt = $jobs->min('created_at');
            $failedJobs = DB::table('failed_jobs');
            $baseline = $this->baselineMaxId();
            if ($baseline === null) {
                return self::FAILURE;
            }
            $candidateFailedJobs = null;
            if ((bool) $this->option('baseline-stdin')) {
                $candidateQuery = clone $failedJobs;
                if ($baseline >= 0) {
                    $candidateQuery->where('id', '>', $baseline);
                }
                $candidateFailedJobs = $candidateQuery->count();
            }

            $this->line(json_encode([
                'generated_at' => now()->utc()->toIso8601String(),
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

    private function baselineMaxId(): ?int
    {
        if (! (bool) $this->option('baseline-stdin')) {
            return -1;
        }

        try {
            $decoded = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! array_key_exists('max_id', $decoded)) {
                throw new \UnexpectedValueException('baseline must contain max_id');
            }
            $maxId = filter_var($decoded['max_id'], FILTER_VALIDATE_INT);
            if ($maxId === false || $maxId < 0) {
                throw new \UnexpectedValueException('max_id must be a non-negative integer');
            }

            return $maxId;
        } catch (Throwable) {
            $this->error('Release health summary: baseline must contain a non-negative integer max_id.');

            return null;
        }
    }
}
