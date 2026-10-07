<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
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
    *schedule:run*)
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

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 6;
        while (microtime(true) < $deadline && ! $condition()) {
            usleep(25_000);
        }

        $this->assertTrue($condition(), 'The scheduler loop did not reach the expected state before the deadline.');
    }
}
