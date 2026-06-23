<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HealthCheck extends Command
{
    protected $signature = 'ops:health {--db} {--queue-schema} {--supervisor}';

    protected $description = 'Check operational health for database, queue schema, and supervisor-managed processes.';

    public function handle(): int
    {
        $healthy = true;

        if ($this->option('db')) {
            try {
                DB::select('select 1');
                $this->info('DB: ok');
            } catch (Throwable $exception) {
                $healthy = false;
                $this->error('DB: failed - '.$exception->getMessage());
            }
        }

        if ($this->option('queue-schema')) {
            foreach (['cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'migrations', 'sessions'] as $table) {
                if (! Schema::hasTable($table)) {
                    $healthy = false;
                    $this->error("Schema: missing {$table}");
                }
            }

            if ($healthy) {
                $this->info('Queue schema: ok');
            }
        }

        if ($this->option('supervisor')) {
            $result = Process::run('supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status php-fpm laravel-worker:*');

            if (! $result->successful() || str_contains($result->output().$result->errorOutput(), 'FATAL') || str_contains($result->output(), 'STOPPED') || str_contains($result->output(), 'EXITED') || ! str_contains($result->output(), 'RUNNING')) {
                $healthy = false;
                $this->error('Supervisor: failed');
                $this->line(trim($result->output().$result->errorOutput()));
            } else {
                $this->info('Supervisor: ok');
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
