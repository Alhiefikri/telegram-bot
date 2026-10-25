<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendBalanceOperationsAlert;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundRecoveryProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TelegramOutboundRecoveryWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recovery_child_that_exceeds_its_deadline_is_terminated(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'sleep(30);']);
        $startedAt = hrtime(true);

        $exitCode = app(TelegramOutboundRecoveryProcessRunner::class)
            ->run($process, maxSeconds: 1, commandName: 'test-recovery');

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->assertSame(124, $exitCode);
        $this->assertFalse($process->isRunning());
        $this->assertLessThan(5, $elapsedSeconds);
    }

    public function test_one_shot_worker_runs_a_real_bounded_child_and_updates_heartbeat(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'outbound-recovery-worker-');
        if ($databasePath === false) {
            $this->fail('Unable to create isolated worker database.');
        }

        $heartbeatPath = storage_path('framework/telegram-outbound-recovery-heartbeat');
        $runningPath = storage_path('framework/telegram-outbound-recovery-running');
        $failuresPath = storage_path('framework/telegram-outbound-recovery-failures');
        @unlink($heartbeatPath);
        @unlink($runningPath);
        @unlink($failuresPath);

        $previousDatabaseEnvironment = [];
        foreach (['DB_CONNECTION', 'DB_DATABASE'] as $name) {
            $previousDatabaseEnvironment[$name] = [
                'process' => getenv($name),
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
            ];
        }

        try {
            putenv('DB_CONNECTION=sqlite');
            putenv('DB_DATABASE='.$databasePath);
            $_SERVER['DB_CONNECTION'] = $_ENV['DB_CONNECTION'] = 'sqlite';
            $_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'] = $databasePath;

            $migration = new Process([PHP_BINARY, base_path('artisan'), 'migrate', '--force'], base_path());
            $migration->setTimeout(90);
            $migration->mustRun();

            $this->artisan('telegram:recover-outbound-worker', ['--once' => true])
                ->assertSuccessful();

            $this->assertFileExists($heartbeatPath);
            $this->assertFileDoesNotExist($runningPath);
            $this->assertFileDoesNotExist($failuresPath);
        } finally {
            foreach ($previousDatabaseEnvironment as $name => $previous) {
                $previous['process'] === false
                    ? putenv($name)
                    : putenv($name.'='.$previous['process']);
                if ($previous['server_exists']) {
                    $_SERVER[$name] = $previous['server'];
                } else {
                    unset($_SERVER[$name]);
                }
                if ($previous['env_exists']) {
                    $_ENV[$name] = $previous['env'];
                } else {
                    unset($_ENV[$name]);
                }
            }
            @unlink($heartbeatPath);
            @unlink($runningPath);
            @unlink($failuresPath);
            @unlink($databasePath);
            @unlink($databasePath.'-journal');
        }
    }

    public function test_recovery_bounds_each_facet_and_rotates_the_page(): void
    {
        Queue::fake();

        foreach (range(1, 8) as $index) {
            TelegramOutboundEffect::factory()->create([
                'effect_key' => 'runtime-recovery-page:'.$index,
                'effect_group_key' => 'runtime-recovery-page:'.$index,
                'effect_type' => TelegramOutboundEffectType::BalanceOperationsAlert->value,
                'source_id' => (string) $index,
                'target_type' => 'admin',
                'queue' => 'notifications',
                'telegram_chat_id' => '900100200',
            ]);
        }

        $this->artisan('telegram:recover-outbound', ['--max-items' => 3, '--max-seconds' => 5])
            ->assertSuccessful();

        Queue::assertPushedTimes(SendBalanceOperationsAlert::class, 3);
        $firstPageIds = [];
        Queue::assertPushed(
            SendBalanceOperationsAlert::class,
            function (SendBalanceOperationsAlert $job) use (&$firstPageIds): bool {
                $firstPageIds[] = $job->effectId;

                return true;
            },
        );
        $this->assertCount(3, array_unique($firstPageIds));

        $this->artisan('telegram:recover-outbound', ['--max-items' => 3, '--max-seconds' => 5])
            ->assertSuccessful();

        Queue::assertPushedTimes(SendBalanceOperationsAlert::class, 6);
        $allIds = [];
        Queue::assertPushed(
            SendBalanceOperationsAlert::class,
            function (SendBalanceOperationsAlert $job) use (&$allIds): bool {
                $allIds[] = $job->effectId;

                return true;
            },
        );

        $this->assertCount(6, array_unique($allIds));
        $this->assertCount(3, array_diff($allIds, $firstPageIds));
    }
}
