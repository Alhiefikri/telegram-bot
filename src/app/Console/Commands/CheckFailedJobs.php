<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckFailedJobs extends Command
{
    protected $signature = 'ops:check-failed-jobs
        {--max=0 : Maximum acceptable failed job count}
        {--snapshot : Print a secret-free JSON baseline with the maximum retained failed-job ID}
        {--baseline= : JSON failed-job ID baseline for bounded tests or manual checks}
        {--baseline-stdin : Count only IDs above a JSON baseline read from standard input}';

    protected $description = 'Alert when failed_jobs exceeds the configured threshold.';

    public function handle(): int
    {
        $max = (int) $this->option('max');
        if ($max < 0) {
            $this->error('ALERT failed_jobs max must be zero or greater.');

            return self::FAILURE;
        }

        try {
            $query = DB::table('failed_jobs');

            if ((bool) $this->option('snapshot')) {
                $this->line(json_encode(
                    ['max_id' => (int) ($query->max('id') ?? 0)],
                    JSON_THROW_ON_ERROR,
                ));

                return self::SUCCESS;
            }

            $baseline = $this->baselineMaxId();
            if ($baseline === null) {
                return self::FAILURE;
            }
            if ($baseline >= 0) {
                $query->where('id', '>', $baseline);
            }

            $count = $query->count();
        } catch (Throwable $e) {
            $this->error('ALERT failed_jobs check could not query database: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($count > $max) {
            $scope = ($this->option('baseline-stdin') || $this->option('baseline')) ? 'failures created after the pre-release baseline' : 'all retained failures';
            $this->error("ALERT failed_jobs count {$count} exceeds max {$max} for {$scope}. Recent job types: ".$this->recentFailedJobTypes($baseline));

            return self::FAILURE;
        }

        $scope = ($this->option('baseline-stdin') || $this->option('baseline')) ? 'failures created after the pre-release baseline' : 'all retained failures';
        $this->info("OK failed_jobs count {$count} is within max {$max} for {$scope}.");

        return self::SUCCESS;
    }

    private function baselineMaxId(): ?int
    {
        $encoded = $this->option('baseline');
        if (! (bool) $this->option('baseline-stdin') && ($encoded === null || trim((string) $encoded) === '')) {
            return -1;
        }

        try {
            $decoded = json_decode(
                (bool) $this->option('baseline-stdin') ? stream_get_contents(STDIN) : (string) $encoded,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (! is_array($decoded) || ! array_key_exists('max_id', $decoded)) {
                throw new \UnexpectedValueException('baseline must contain max_id');
            }
            $maxId = filter_var($decoded['max_id'], FILTER_VALIDATE_INT);
            if ($maxId === false || $maxId < 0) {
                throw new \UnexpectedValueException('max_id must be a non-negative integer');
            }

            return $maxId;
        } catch (Throwable) {
            $this->error('ALERT failed_jobs baseline must contain a non-negative integer max_id.');

            return null;
        }
    }

    private function recentFailedJobTypes(?int $baseline): string
    {
        $types = [];

        $query = DB::table('failed_jobs')->orderByDesc('failed_at');
        if ($baseline >= 0) {
            $query->where('id', '>', $baseline);
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
