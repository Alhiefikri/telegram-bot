<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SchedulerLoopTest extends TestCase
{
    public function test_failed_cycle_alerts_and_does_not_advance_heartbeat_until_recovery(): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-loop-'.bin2hex(random_bytes(8));
        $bin = $workspace.'/bin';
        File::ensureDirectoryExists($bin);

        $idScript = $bin.'/id';
        file_put_contents($idScript, "#!/bin/sh\nprintf '1000\\n'\n");
        chmod($idScript, 0755);

        $phpScript = $bin.'/php';
        file_put_contents($phpScript, <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$SCHEDULER_TEST_LOG"
case "$*" in
    *schedule:run*|*ops:schedule-run*)
        if [ ! -f "$SCHEDULER_TEST_FAILED" ]; then
            : > "$SCHEDULER_TEST_FAILED"
            printf 'oom_kill 1\n' > "$SCHEDULER_TEST_OOM_EVENTS"
            exit 7
        fi
        ;;
esac
exit 0
SH);
        chmod($phpScript, 0755);

        $oomEvents = $workspace.'/memory.events';
        file_put_contents($oomEvents, "oom_kill 0\n");

        $heartbeat = $workspace.'/scheduler-heartbeat';
        $log = $workspace.'/calls.log';
        $process = new Process([
            '/bin/sh',
            base_path('../docker-scheduler.sh'),
        ], $workspace, [
            'PATH' => $bin.':'.(string) getenv('PATH'),
            'SCHEDULER_APP_PATH' => $workspace,
            'SCHEDULER_HEARTBEAT_FILE' => $heartbeat,
            'SCHEDULER_RUNNING_FILE' => $workspace.'/scheduler-running',
            'SCHEDULER_OOM_COUNT_FILE' => $workspace.'/oom-count',
            'SCHEDULER_OOM_EVENTS_FILE' => $oomEvents,
            'SCHEDULER_TEST_OOM_EVENTS' => $oomEvents,
            'SCHEDULER_INTERVAL_SECONDS' => '1',
            'SCHEDULER_TEST_LOG' => $log,
            'SCHEDULER_TEST_FAILED' => $workspace.'/failed-once',
        ]);

        try {
            $process->start();

            $this->waitFor(
                fn (): bool => is_file($log)
                    && str_contains((string) file_get_contents($log), 'ops:scheduler-runtime-alert scheduler failed --reason=schedule_exit'),
            );
            $this->assertFileDoesNotExist($heartbeat);

            $this->waitFor(fn (): bool => is_file($heartbeat));
            $calls = (string) file_get_contents($log);
            $this->assertStringContainsString('ops:scheduler-runtime-alert scheduler failed --reason=oom_kill', $calls);

            $this->assertStringContainsString('ops:scheduler-runtime-alert scheduler recovered', $calls);
            $this->assertSame(1, substr_count($calls, 'ops:scheduler-runtime-alert scheduler failed --reason=schedule_exit'));
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }

            File::deleteDirectory($workspace);
        }
    }

    public function test_successful_command_with_new_oom_does_not_report_recovery_or_advance_heartbeat(): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-oom-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/bin');
        file_put_contents($workspace.'/memory.events', "oom_kill 0\n");
        file_put_contents($workspace.'/bin/id', "#!/bin/sh\nprintf '1000\\n'\n");
        file_put_contents($workspace.'/bin/sleep', "#!/bin/sh\nexit 99\n");
        file_put_contents($workspace.'/bin/php', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$SCHEDULER_TEST_LOG"
case "$*" in
    *schedule:run*|*ops:schedule-run*)
        printf 'oom_kill 1\n' > "$SCHEDULER_OOM_EVENTS_FILE"
        ;;
esac
SH);
        foreach (['id', 'sleep', 'php'] as $binary) {
            chmod($workspace.'/bin/'.$binary, 0755);
        }
        $process = new Process(['/bin/sh', base_path('../docker-scheduler.sh')], $workspace, [
            'PATH' => $workspace.'/bin:'.(string) getenv('PATH'),
            'SCHEDULER_APP_PATH' => $workspace,
            'SCHEDULER_HEARTBEAT_FILE' => $workspace.'/heartbeat',
            'SCHEDULER_RUNNING_FILE' => $workspace.'/running',
            'SCHEDULER_OOM_COUNT_FILE' => $workspace.'/oom-count',
            'SCHEDULER_OOM_EVENTS_FILE' => $workspace.'/memory.events',
            'SCHEDULER_TEST_LOG' => $workspace.'/calls',
        ]);
        try {
            $this->assertSame(99, $process->run());
            $calls = (string) file_get_contents($workspace.'/calls');
            $this->assertStringContainsString('scheduler failed --reason=oom_kill', $calls);
            $this->assertStringNotContainsString('scheduler recovered', $calls);
            $this->assertFileDoesNotExist($workspace.'/heartbeat');
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public static function cycleOutcomes(): array
    {
        return [
            'handled domain failure' => ['domain', true, false],
            'success' => ['success', true, true],
            'PHP fatal error' => ['fatal', false, false],
            'killed child' => ['killed', false, false],
            'unexpected child exit' => ['unexpected', false, false],
            'before callback failed' => ['before', false, false],
            'after callback failed' => ['after', false, false],
            'another cycle token' => ['stale-token', false, false],
            'domain failure with OOM' => ['oom', false, false],
        ];
    }

    #[DataProvider('cycleOutcomes')]
    public function test_liveness_requires_matching_safe_completion_and_does_not_hide_task_failures(string $mode, bool $progress, bool $recovered): void
    {
        $workspace = sys_get_temp_dir().'/scheduler-liveness-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/bin');
        File::ensureDirectoryExists($workspace.'/framework');
        File::ensureDirectoryExists($workspace.'/logs');
        $heartbeat = $workspace.'/framework/scheduler-heartbeat';
        $staleHeartbeat = "2000-01-01T00:00:00Z\n";
        file_put_contents($heartbeat, $staleHeartbeat);
        file_put_contents($workspace.'/memory.events', "oom_kill 0\n");
        file_put_contents($workspace.'/framework/scheduler-cycle-completed', "old-cycle\n");
        file_put_contents($workspace.'/bin/id', "#!/bin/sh\nprintf '1000\\n'\n");
        file_put_contents($workspace.'/bin/sleep', "#!/bin/sh\nexit 99\n");
        file_put_contents($workspace.'/bin/php', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$SCHEDULER_TEST_LOG"
case "$*" in
    *ops:schedule-run*)
        exec "$SCHEDULER_TEST_PHP" "$SCHEDULER_TEST_RUNNER" "$SCHEDULER_APP_PATH" "$@"
        ;;
esac
SH);
        foreach (['id', 'sleep', 'php'] as $binary) {
            chmod($workspace.'/bin/'.$binary, 0755);
        }
        $process = new Process(['/bin/sh', base_path('../docker-scheduler.sh')], $workspace, [
            'PATH' => $workspace.'/bin:'.(string) getenv('PATH'),
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=',
            'APP_CONFIG_CACHE' => $workspace.'/no-config.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'LOG_CHANNEL' => 'stderr',
            'SCHEDULER_APP_PATH' => $workspace,
            'SCHEDULER_HEARTBEAT_FILE' => $heartbeat,
            'SCHEDULER_RUNNING_FILE' => $workspace.'/framework/scheduler-running',
            'SCHEDULER_OOM_COUNT_FILE' => $workspace.'/oom-count',
            'SCHEDULER_OOM_EVENTS_FILE' => $workspace.'/memory.events',
            'SCHEDULER_TEST_LOG' => $workspace.'/calls',
            'SCHEDULER_TEST_PHP' => PHP_BINARY,
            'SCHEDULER_TEST_RUNNER' => base_path('tests/Fixtures/bounded-scheduler-liveness.php'),
            'SCHEDULER_TEST_MODE' => $mode,
        ], timeout: 15);
        try {
            $this->assertSame(99, $process->run(), $process->getErrorOutput());
            $this->assertFileExists($workspace.'/remaining-completed');
            if ($progress) {
                $this->assertNotSame($staleHeartbeat, file_get_contents($heartbeat));
            } else {
                $this->assertSame($staleHeartbeat, file_get_contents($heartbeat));
            }
            $calls = file_get_contents($workspace.'/calls');
            if ($recovered) {
                $this->assertStringContainsString('scheduler recovered', $calls);
                $this->assertStringNotContainsString('scheduler failed', $calls);
            } else {
                $this->assertStringContainsString('status=1', $process->getErrorOutput());
                $this->assertStringContainsString('scheduler failed --reason=schedule_exit', $calls);
                $this->assertStringNotContainsString('scheduler recovered', $calls);
            }
            if ($mode === 'oom') {
                $this->assertStringContainsString('scheduler failed --reason=oom_kill', $calls);
            }
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 6;
        while (microtime(true) < $deadline && ! $condition()) {
            usleep(25_000);
        }

        $this->assertTrue($condition(), 'The scheduler loop did not reach the expected state before the deadline.');
    }
}
