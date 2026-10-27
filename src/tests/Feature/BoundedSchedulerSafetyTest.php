<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class BoundedSchedulerSafetyTest extends TestCase
{
    private string $workspace;

    private string $originalStorage;

    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/scheduler-safety-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workspace.'/framework');
        $this->originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($this->workspace);
        $this->schedule = new Schedule;
        $this->app->instance(Schedule::class, $this->schedule);
        file_put_contents($this->workspace.'/task.php', <<<'PHP'
<?php
[$script, $workspace, $id, $mode] = $argv;
$record = function (string $state) use ($workspace, $id): void {
    file_put_contents($workspace.'/trace', $id.' '.$state."\n", FILE_APPEND | LOCK_EX);
};
$record('start');
if ($mode === 'wait') {
    $deadline = microtime(true) + 3;
    while (! is_file($workspace.'/release') && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($workspace.'/release')) {
        $record('starved');
        exit(29);
    }
} elseif ($mode === 'release') {
    touch($workspace.'/release');
} elseif ($mode === 'killed') {
    posix_kill(getmypid(), SIGKILL);
}
$record('end');
exit($mode === 'failed' ? 7 : 0);
PHP);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_a_free_slot_is_reused_while_another_provider_is_still_waiting(): void
    {
        $this->task('slow', 'wait');
        $this->task('fast');
        $this->task('next', 'release');

        $this->assertSame(0, Artisan::call('ops:schedule-run'));
        $trace = $this->trace();
        $this->assertNotContains('slow starved', $trace);
        $this->assertLessThan(array_search('slow end', $trace, true), array_search('next start', $trace, true));
        $this->assertContains('fast end', $trace);
    }

    public function test_failure_before_launch_releases_the_mutex_and_does_not_discard_other_work(): void
    {
        $failed = $this->task('before-failed');
        $failed->before(fn () => throw new RuntimeException('Fixture preparation failed.'));
        $this->task('remaining');

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertNotContains('before-failed start', $this->trace());
        $this->assertContains('remaining end', $this->trace());
        $this->assertFalse($failed->mutex->exists($failed));
    }

    public function test_failure_in_completion_callbacks_releases_the_mutex_and_other_tasks_still_finish(): void
    {
        $failed = $this->task('after-failed');
        $failed->after(fn () => throw new RuntimeException('Fixture completion failed.'));
        $this->task('remaining');

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertContains('after-failed end', $this->trace());
        $this->assertContains('remaining end', $this->trace());
        $this->assertFalse($failed->mutex->exists($failed));
    }

    public function test_killed_child_is_a_failure_and_its_slot_is_not_lost(): void
    {
        $killed = $this->task('killed', 'killed');
        $this->task('second');
        $this->task('third');

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertNotSame(0, $killed->exitCode);
        $this->assertNotContains('killed end', $this->trace());
        $this->assertContains('third end', $this->trace());
        $this->assertFalse($killed->mutex->exists($killed));
    }

    public function test_failure_callbacks_never_report_a_failed_child_as_successful(): void
    {
        $callbacks = [];
        $failed = $this->task('failed', 'failed');
        $success = $this->task('success');
        foreach (['failed' => $failed, 'success' => $success] as $id => $event) {
            $event->onSuccess(function () use (&$callbacks, $id): void {
                $callbacks[] = $id.' success';
            });
            $event->onFailure(function () use (&$callbacks, $id): void {
                $callbacks[] = $id.' failure';
            });
        }

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertEqualsCanonicalizing(['failed failure', 'success success'], $callbacks);
    }

    public function test_foreground_failure_does_not_suppress_the_remaining_background_recovery(): void
    {
        $this->task('foreground', 'failed', background: false);
        $this->task('recovery');

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertContains('recovery end', $this->trace());
    }

    public function test_throwing_foreground_preparation_fails_the_cycle_instead_of_reporting_recovery(): void
    {
        $failed = $this->task('foreground', background: false);
        $failed->before(fn () => throw new RuntimeException('Fixture foreground preparation failed.'));
        $this->task('remaining');

        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $this->assertNotContains('foreground start', $this->trace());
        $this->assertContains('remaining end', $this->trace());
        $this->assertFalse($failed->mutex->exists($failed));
    }

    public function test_a_skipped_foreground_mutex_does_not_make_a_healthy_cycle_fail_or_unlock_its_owner(): void
    {
        $locked = $this->task('locked', background: false);
        $this->assertTrue($locked->mutex->create($locked));
        $this->task('remaining');

        try {
            $this->assertSame(0, Artisan::call('ops:schedule-run'));
            $this->assertNotContains('locked start', $this->trace());
            $this->assertContains('remaining end', $this->trace());
            $this->assertTrue($locked->mutex->exists($locked));
        } finally {
            $locked->mutex->forget($locked);
        }
    }

    public function test_eligibility_filters_are_respected_before_work_enters_the_pool(): void
    {
        $this->task('not-eligible')->when(fn (): bool => false);
        $this->task('skipped')->skip(fn (): bool => true);
        $this->task('eligible');

        $this->assertSame(0, Artisan::call('ops:schedule-run'));
        $this->assertEqualsCanonicalizing(['eligible start', 'eligible end'], $this->trace());
    }

    public function test_a_failed_cycle_does_not_poison_the_next_cycle_or_leave_the_cycle_lock_held(): void
    {
        $event = $this->task('retry', 'failed');
        $this->assertSame(1, Artisan::call('ops:schedule-run'));
        $event->command = str_replace(' failed', ' success', $event->command);

        $this->assertSame(0, Artisan::call('ops:schedule-run'));
        $this->assertSame(0, $event->exitCode);
        $this->assertSame(2, count(array_filter($this->trace(), fn (string $line): bool => $line === 'retry end')));
    }

    private function task(string $id, string $mode = 'success', bool $background = true): Event
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->workspace.'/task.php').' '.escapeshellarg($this->workspace).' '.$id.' '.$mode;
        $event = $this->schedule->exec($command)->everyMinute()->withoutOverlapping();
        if ($background) {
            $event->runInBackground();
        }

        return $event;
    }

    private function trace(): array
    {
        return file($this->workspace.'/trace', FILE_IGNORE_NEW_LINES);
    }
}
