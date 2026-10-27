<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BoundedSchedulerTest extends TestCase
{
    public function test_due_work_is_bounded_without_blocking_foreground_work_and_releases_failed_task_mutexes(): void
    {
        $workspace = sys_get_temp_dir().'/bounded-scheduler-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/framework');
        $originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($workspace);
        $fixture = $workspace.'/task.php';
        file_put_contents($fixture, <<<'PHP'
<?php
[$script, $workspace, $id] = $argv;
$record = function (string $state) use ($workspace, $id): void {
    file_put_contents($workspace.'/events', json_encode([$state, $id])."\n", FILE_APPEND | LOCK_EX);
};
$record('start');
$memory = str_repeat('x', 24 * 1024 * 1024);
if (in_array($id, ['slow', 'fast'], true)) {
    touch($workspace.'/'.$id.'-started');
    $other = $id === 'slow' ? 'fast' : 'slow';
    $deadline = microtime(true) + 3;
    while (! is_file($workspace.'/'.$other.'-started') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($workspace.'/'.$other.'-started')) {
        $record('blocked');
        exit(23);
    }
}
usleep(150_000);
$record('end');
exit($id === 'failed' ? 7 : 0);
PHP);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        $events = [];
        foreach (['slow', 'fast', 'failed', 'last'] as $id) {
            $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($workspace).' '.$id;
            $event = $schedule->exec($command)->everyMinute()->withoutOverlapping()->runInBackground();
            $event->before(function () use ($workspace): void {
                $this->assertFileExists($workspace.'/foreground');
            });
            $events[] = $event;
        }
        $schedule->call(fn () => touch($workspace.'/foreground'))->everyMinute();
        $skipped = $schedule->exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($workspace).' skipped')
            ->everyMinute()->withoutOverlapping()->runInBackground();
        $this->assertTrue($skipped->mutex->create($skipped));
        $failureCallback = null;
        $events[2]->onFailure(function () use (&$failureCallback): void {
            $failureCallback = 'failed';
        });

        try {
            $this->assertSame(1, Artisan::call('ops:schedule-run'));
            $active = 0;
            $peak = 0;
            $started = [];
            foreach (file($workspace.'/events', FILE_IGNORE_NEW_LINES) as $line) {
                [$state, $id] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $this->assertNotSame('blocked', $state, 'A slow provider prevented independent work from starting.');
                if ($state === 'start') {
                    $active++;
                    $peak = max($peak, $active);
                    $started[] = $id;
                } else {
                    $active--;
                }
            }
            $this->assertSame(2, $peak);
            $this->assertSame(0, $active);
            $this->assertEqualsCanonicalizing(['slow', 'fast', 'failed', 'last'], $started);
            $this->assertSame('failed', $failureCallback);
            foreach ($events as $event) {
                $this->assertFalse($event->mutex->exists($event));
            }
            $this->assertTrue($skipped->mutex->exists($skipped));
        } finally {
            $skipped->mutex->forget($skipped);
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($workspace);
        }
    }

    public function test_a_second_cycle_cannot_start_work_until_the_first_cycle_releases_its_lock(): void
    {
        $workspace = sys_get_temp_dir().'/bounded-cycle-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/framework');
        $originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($workspace);
        $lock = fopen($workspace.'/framework/scheduler-cycle.lock', 'c');
        flock($lock, LOCK_EX);
        $runs = 0;
        $schedule = new Schedule;
        $schedule->call(function () use (&$runs): void {
            $runs++;
        })->everyMinute();
        $this->app->instance(Schedule::class, $schedule);

        try {
            $this->assertSame(1, Artisan::call('ops:schedule-run'));
            $this->assertSame(0, $runs);
            flock($lock, LOCK_UN);
            $this->assertSame(0, Artisan::call('ops:schedule-run'));
            $this->assertSame(1, $runs);
        } finally {
            fclose($lock);
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($workspace);
        }
    }
}
