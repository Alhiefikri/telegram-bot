<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BoundedSchedulerProcessTest extends TestCase
{
    public static function terminationSignals(): array
    {
        return [
            'TERM pool' => [SIGTERM, 'scheduler', ['first', 'second']],
            'INT pool' => [SIGINT, 'scheduler', ['first', 'second']],
            'QUIT pool' => [SIGQUIT, 'scheduler', ['first', 'second']],
            'TERM priority' => [SIGTERM, 'foreground', ['first', 'second']],
            'INT priority' => [SIGINT, 'foreground', ['first', 'second']],
            'QUIT priority' => [SIGQUIT, 'foreground', ['first', 'second']],
        ];
    }

    #[DataProvider('terminationSignals')]
    public function test_termination_stops_children_without_starting_queued_work_and_preserves_overlap_protection(int $signal, string $phase, array $expectedChildren): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-process-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/framework/cache/data');
        File::ensureDirectoryExists($workspace.'/logs');
        file_put_contents($workspace.'/framework/scheduler-cycle-completed', "previous-cycle\n");
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/bounded-scheduler-process.php'),
            $workspace,
            $phase,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=',
            'APP_CONFIG_CACHE' => $workspace.'/no-config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'file',
            'LOG_CHANNEL' => 'stderr',
        ], timeout: 15);
        $children = [];
        try {
            $process->start();
            $deadline = microtime(true) + 7;
            do {
                $children = is_file($workspace.'/children')
                    ? array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/children', FILE_IGNORE_NEW_LINES))
                    : [];
                if (count($children) === count($expectedChildren) || ! $process->isRunning()) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertCount(count($expectedChildren), $children, $process->getOutput().$process->getErrorOutput());
            config([
                'cache.stores.file.path' => $workspace.'/framework/cache/data',
                'cache.stores.file.lock_path' => $workspace.'/framework/cache/data',
            ]);
            $store = $this->app->make('cache')->store('file');
            $mutexes = json_decode(file_get_contents($workspace.'/mutexes'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($expectedChildren as $id) {
                $this->assertFalse($store->lock($mutexes[$id], 120)->get(fn (): bool => true));
            }
            $process->signal($signal);
            $this->assertSame(1, $process->wait());
            $this->assertFileDoesNotExist($workspace.'/framework/scheduler-cycle-completed');
            $allChildren = array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($workspace.'/children', FILE_IGNORE_NEW_LINES));
            $this->assertEqualsCanonicalizing($expectedChildren, array_column($allChildren, 'id'));
            foreach ($children as $child) {
                $this->assertFalse(posix_kill($child['pid'], 0), 'A scheduler child survived termination.');
            }
            $cycleLock = fopen($workspace.'/framework/scheduler-cycle.lock', 'c');
            $this->assertTrue(flock($cycleLock, LOCK_EX | LOCK_NB));
            fclose($cycleLock);

            foreach ($expectedChildren as $id) {
                $this->assertTrue(
                    $store->lock($mutexes[$id], 120)->get(fn (): bool => true),
                    'A confirmed-dead child must not block recovery until a long mutex expires.',
                );
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            foreach ($children as $child) {
                if (posix_kill($child['pid'], 0)) {
                    posix_kill($child['pid'], SIGKILL);
                }
            }
            File::deleteDirectory($workspace);
        }
    }
}
