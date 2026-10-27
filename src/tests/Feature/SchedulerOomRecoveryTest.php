<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SchedulerOomRecoveryTest extends TestCase
{
    public function test_unchanged_oom_counter_does_not_replay_an_old_incident(): void
    {
        $result = $this->runLoop('stable', previous: 5, current: 5);

        $this->assertTrue($result['heartbeat']);
        $this->assertFalse($result['running']);
        $this->assertStringNotContainsString('scheduler failed', $result['calls']);
        $this->assertSame('5', $result['counter']);
    }

    public function test_counter_reset_after_container_recreation_does_not_raise_a_false_oom(): void
    {
        $result = $this->runLoop('stable', previous: 9, current: 0);

        $this->assertTrue($result['heartbeat']);
        $this->assertStringNotContainsString('scheduler failed', $result['calls']);
        $this->assertSame('0', $result['counter']);
    }

    public function test_failed_alert_persistence_keeps_the_counter_retryable_and_does_not_report_health(): void
    {
        $result = $this->runLoop('retry-alert', previous: 0, current: 0);

        $this->assertFalse($result['heartbeat']);
        $this->assertTrue($result['running']);
        $this->assertFalse($result['premature_recovery']);
        $this->assertSame('0', $result['counter']);
        $this->assertStringNotContainsString('scheduler recovered', $result['calls']);
    }

    public function test_oom_alert_is_retried_then_only_a_later_clean_cycle_can_resolve_it(): void
    {
        $result = $this->runLoop('retry-alert', previous: 0, current: 0, cycles: 3);

        $this->assertTrue($result['heartbeat']);
        $this->assertFalse($result['running']);
        $this->assertFalse($result['premature_recovery']);
        $this->assertSame('1', $result['counter']);
        $this->assertSame(2, substr_count($result['calls'], 'scheduler failed --reason=oom_kill'));
        $this->assertSame(1, substr_count($result['calls'], 'scheduler recovered'));
    }

    public function test_an_interrupted_cycle_is_reported_before_the_next_clean_cycle_resolves_it(): void
    {
        $result = $this->runLoop('interrupted', previous: 0, current: 0);

        $this->assertTrue($result['heartbeat']);
        $this->assertFalse($result['running']);
        $this->assertStringContainsString('scheduler failed --reason=cycle_interrupted', $result['calls']);
        $this->assertLessThan(strpos($result['calls'], 'scheduler recovered'), strpos($result['calls'], 'scheduler failed'));
    }

    private function runLoop(string $scenario, int $previous, int $current, int $cycles = 1): array
    {
        $workspace = sys_get_temp_dir().'/scheduler-oom-recovery-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($workspace.'/bin');
        file_put_contents($workspace.'/memory.events', 'oom_kill '.$current."\n");
        file_put_contents($workspace.'/counter', $previous."\n");
        if ($scenario === 'interrupted') {
            touch($workspace.'/running');
        }
        file_put_contents($workspace.'/bin/id', "#!/bin/sh\nprintf '1000\\n'\n");
        file_put_contents($workspace.'/bin/sleep', <<<'SH'
#!/bin/sh
cycle=$(cat "$SCHEDULER_APP_PATH/cycle")
[ "$cycle" -lt "$STOP_AFTER_CYCLES" ] || exit 99
SH);
        file_put_contents($workspace.'/bin/php', <<<'SH'
#!/bin/sh
set -eu
root=$SCHEDULER_APP_PATH
printf '%s\n' "$*" >> "$root/calls"
case "$*" in
    *ops:schedule-run*)
        cycle=0
        if [ -f "$root/cycle" ]; then cycle=$(cat "$root/cycle"); fi
        cycle=$((cycle + 1))
        printf '%s\n' "$cycle" > "$root/cycle"
        if [ "$TEST_SCENARIO" = retry-alert ]; then
            printf 'oom_kill 1\n' > "$root/memory.events"
        fi
        ;;
    *scheduler\ failed\ --reason=oom_kill*)
        if [ "$TEST_SCENARIO" = retry-alert ] && [ ! -f "$root/persist-attempted" ]; then
            touch "$root/persist-attempted"
            exit 7
        fi
        ;;
    *scheduler\ recovered*)
        if [ "$TEST_SCENARIO" = retry-alert ] && [ "$(cat "$root/cycle")" -lt 3 ]; then
            touch "$root/premature-recovery"
        fi
        ;;
esac
SH);
        foreach (['id', 'sleep', 'php'] as $name) {
            chmod($workspace.'/bin/'.$name, 0755);
        }
        $process = new Process(['/bin/sh', base_path('../docker-scheduler.sh')], $workspace, [
            'PATH' => $workspace.'/bin:'.getenv('PATH'),
            'SCHEDULER_APP_PATH' => $workspace,
            'SCHEDULER_HEARTBEAT_FILE' => $workspace.'/heartbeat',
            'SCHEDULER_RUNNING_FILE' => $workspace.'/running',
            'SCHEDULER_OOM_COUNT_FILE' => $workspace.'/counter',
            'SCHEDULER_OOM_EVENTS_FILE' => $workspace.'/memory.events',
            'TEST_SCENARIO' => $scenario,
            'STOP_AFTER_CYCLES' => (string) $cycles,
        ], timeout: 8);

        try {
            $this->assertSame(99, $process->run());

            return [
                'heartbeat' => is_file($workspace.'/heartbeat'),
                'running' => is_file($workspace.'/running'),
                'premature_recovery' => is_file($workspace.'/premature-recovery'),
                'counter' => trim(file_get_contents($workspace.'/counter')),
                'calls' => file_get_contents($workspace.'/calls'),
            ];
        } finally {
            File::deleteDirectory($workspace);
        }
    }
}
