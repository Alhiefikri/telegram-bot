<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Process-level PostgreSQL race harness shared by the balance concurrency
 * tests: forked workers all wait behind one start marker, results are
 * collected as JSON, and every test refuses to run outside an isolated
 * testing database. SQLite/sync tests never use this trait; only the
 * PostgreSQL lanes prove locking.
 */
trait RunsPostgresWorkers
{
    /**
     * @param  callable(int): string  $work
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    protected function runConcurrentWorkers(int $count, callable $work, string $workspacePrefix = 'usdt-race'): array
    {
        $directory = $this->createWorkspace($workspacePrefix);
        $children = [];

        try {
            DB::disconnect();
            for ($index = 0; $index < $count; $index++) {
                $children[$index] = $this->forkWorker($directory, $index, $work);
            }

            foreach (range(0, $count - 1) as $index) {
                $this->waitForMarker($directory.'/ready-'.$index);
            }
            touch($directory.'/start');

            return $this->collectResults($children, $directory);
        } finally {
            $this->cleanupWorkspace($directory);
            DB::reconnect();
        }
    }

    protected function createWorkspace(string $prefix = 'usdt-race'): string
    {
        $directory = storage_path('framework/testing/'.$prefix.'-'.bin2hex(random_bytes(8)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create balance race workspace [{$directory}].");
        }

        return $directory;
    }

    protected function forkWorker(string $directory, int $index, callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Unable to fork balance concurrency worker.');
        }

        if ($pid === 0) {
            $this->runWorker(
                $work,
                $index,
                $directory.'/ready-'.$index,
                $directory.'/start',
                $directory.'/result-'.$index.'.json',
            );
        }

        return $pid;
    }

    /**
     * @param  callable(int): string  $work
     */
    protected function runWorker(callable $work, int $index, string $readyFile, string $startFile, string $resultFile): never
    {
        try {
            DB::disconnect();
            touch($readyFile);
            $this->waitForMarker($startFile);
            $value = $work($index);
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(0);
        } catch (Throwable $exception) {
            file_put_contents($resultFile, json_encode(['ok' => false, 'class' => $exception::class], JSON_THROW_ON_ERROR), LOCK_EX);
            exit(1);
        }
    }

    /**
     * @param  array<int, int>  $children
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int}>
     */
    protected function collectResults(array $children, string $directory): array
    {
        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            $resultPath = $directory.'/result-'.$index.'.json';
            $result = is_file($resultPath)
                ? json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR)
                : ['ok' => false, 'class' => RuntimeException::class];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
        }

        return $results;
    }

    protected function waitForMarker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for a balance concurrency worker.');
            }
            usleep(10_000);
        }
    }

    protected function cleanupWorkspace(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    /**
     * @param  list<array{ok: bool, value?: string, class?: string, exit_code: int}>  $results
     */
    protected function assertWorkersSucceeded(array $results): void
    {
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(0, $result['exit_code'], json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    protected function assertDatabaseConstraintRejects(callable $statement, string $expectedConstraint): void
    {
        try {
            $statement();
            $this->fail("The database constraint [{$expectedConstraint}] was expected to reject this mutation.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($expectedConstraint, $exception->getMessage());
        }
    }

    protected function requirePostgresConcurrency(string $context = 'Balance'): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped("{$context} PostgreSQL races require the pgsql test connection.");
        }
        if ((string) env('RUN_CONCURRENCY_TESTS', '') !== '1') {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 to run process-level PostgreSQL races.');
        }
        $this->assertSafeConcurrencyDatabase($context);
    }

    protected function assertSafeConcurrencyDatabase(string $context = 'Balance'): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->fail("The pcntl extension is required for {$context} PostgreSQL races.");
        }
        if (! extension_loaded('pdo_pgsql')) {
            $this->fail("The pdo_pgsql extension is required for {$context} PostgreSQL races.");
        }
        if (app()->environment() !== 'testing') {
            $this->fail("Refusing to run {$context} races outside APP_ENV=testing.");
        }
        if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.driver') !== 'pgsql') {
            $this->fail("{$context} races require DB_CONNECTION=pgsql.");
        }
        if ((string) config('database.connections.pgsql.url') !== '' || (string) env('DB_URL', '') !== '') {
            $this->fail("Refusing to run {$context} races while DB_URL is configured.");
        }
        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || ! preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database)) {
            $this->fail("{$context} races require an isolated test database; got [{$database}].");
        }
        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if ($actualDatabase !== $database) {
            $this->fail("Connected database [{$actualDatabase}] does not match configured database [{$database}].");
        }
    }
}
