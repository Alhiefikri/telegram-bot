<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramOutboundRecoveryStageRunner;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TelegramOutboundRecoveryStageRunnerTest extends TestCase
{
    public function test_a_failed_stage_does_not_stop_later_stages(): void
    {
        $runner = new TelegramOutboundRecoveryStageRunner;
        $executed = [];

        $results = $runner->run([
            'stock_notification' => function () use (&$executed): void {
                $executed[] = 'stock_notification';
                throw new RuntimeException('statement timeout');
            },
            'order_lifecycle' => function () use (&$executed): int {
                $executed[] = 'order_lifecycle';

                return 7;
            },
        ], 10);

        $this->assertSame(['order_lifecycle' => 7], $results);
        $this->assertSame(['stock_notification', 'order_lifecycle'], $executed);
        $this->assertSame(['stock_notification' => RuntimeException::class], $runner->failures());
        $this->assertTrue($runner->failed());
    }

    public function test_exhausting_the_budget_defers_work_and_the_next_sweep_starts_with_it(): void
    {
        $time = 0.0;
        $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
            return $time;
        });
        $executed = [];
        $operations = [];
        foreach (['receipts', 'stock', 'broadcasts'] as $stage) {
            $operations[$stage] = function () use ($stage, &$time, &$executed): string {
                $executed[] = $stage;
                $time += 2;

                return $stage;
            };
        }

        $this->assertSame(['receipts' => 'receipts'], $runner->run($operations, 2));
        $this->assertFalse($runner->failed());
        $this->assertSame(['stock' => 'stock'], $runner->run($operations, 2));
        $this->assertSame(['broadcasts' => 'broadcasts'], $runner->run($operations, 2));
        $this->assertSame(['receipts', 'stock', 'broadcasts'], $executed);
    }

    public function test_repeated_slow_failures_cannot_starve_other_stages_across_new_runners(): void
    {
        $time = 0.0;
        $executed = [];
        $operations = [];
        foreach (['receipts', 'stock', 'broadcasts', 'order_lifecycle'] as $stage) {
            $operations[$stage] = function () use ($stage, &$time, &$executed): string {
                $executed[] = $stage;
                $time += $stage === 'stock' ? 20 : 2;
                if ($stage === 'stock') {
                    throw new RuntimeException('statement timeout');
                }

                return $stage;
            };
        }

        foreach (['receipts', 'stock', 'broadcasts', 'order_lifecycle', 'receipts', 'stock', 'broadcasts', 'order_lifecycle'] as $expectedStage) {
            $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
                return $time;
            });

            $results = $runner->run($operations, 1);

            $this->assertSame($expectedStage === 'stock' ? [] : [$expectedStage => $expectedStage], $results);
            $this->assertSame(
                $expectedStage === 'stock' ? ['stock' => RuntimeException::class] : [],
                $runner->failures(),
            );
        }

        $this->assertSame([
            'receipts', 'stock', 'broadcasts', 'order_lifecycle',
            'receipts', 'stock', 'broadcasts', 'order_lifecycle',
        ], $executed);
    }

    public static function exhaustedAdmissionBudgets(): array
    {
        return [
            'zero budget' => [0.0, [0.0, 0.0]],
            'exact deadline before admission' => [1.0, [0.0, 1.0]],
        ];
    }

    #[DataProvider('exhaustedAdmissionBudgets')]
    public function test_an_exhausted_budget_does_not_consume_the_saved_stage(float $budget, array $clockReadings): void
    {
        $time = 0.0;
        $seed = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
            return $time;
        });
        $seed->run([
            'receipts' => function () use (&$time): void {
                $time = 2.0;
            },
            'stock' => fn (): string => 'stock',
            'broadcasts' => fn (): string => 'broadcasts',
        ], 1);

        $executed = [];
        $operations = [];
        foreach (['receipts', 'stock', 'broadcasts'] as $stage) {
            $operations[$stage] = function () use ($stage, &$executed): string {
                $executed[] = $stage;

                return $stage;
            };
        }
        $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$clockReadings): float {
            return array_shift($clockReadings);
        });

        $this->assertSame([], $runner->run($operations, $budget));
        $this->assertSame([], $executed);
        $this->assertFalse($runner->failed());
        $this->assertSame(
            ['stock' => 'stock', 'broadcasts' => 'broadcasts', 'receipts' => 'receipts'],
            (new TelegramOutboundRecoveryStageRunner)->run($operations, 10),
        );
        $this->assertSame(['stock', 'broadcasts', 'receipts'], $executed);
    }

    public function test_a_renamed_saved_stage_falls_back_to_a_complete_current_sweep(): void
    {
        $time = 0.0;
        $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
            return $time;
        });
        $runner->run([
            'receipts' => function () use (&$time): void {
                $time += 2;
            },
            'stock' => fn (): int => 1,
            'broadcasts' => fn (): int => 2,
        ], 1);
        $executed = [];
        $operations = [];
        foreach (['receipts', 'stock_candidates', 'broadcasts'] as $stage) {
            $operations[$stage] = function () use ($stage, &$executed): string {
                $executed[] = $stage;

                return $stage;
            };
        }

        $this->assertSame([
            'receipts' => 'receipts',
            'stock_candidates' => 'stock_candidates',
            'broadcasts' => 'broadcasts',
        ], $runner->run($operations, 10));
        $this->assertSame(['receipts', 'stock_candidates', 'broadcasts'], $executed);
    }

    public function test_a_resumed_sweep_wraps_once_and_the_following_sweep_starts_fresh(): void
    {
        $time = 0.0;
        $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
            return $time;
        });
        $executed = [];
        $operations = [];
        foreach (['receipts', 'stock', 'broadcasts'] as $stage) {
            $operations[$stage] = function () use ($stage, &$time, &$executed): string {
                $executed[] = $stage;
                $time += 2;

                return $stage;
            };
        }
        $runner->run($operations, 1);
        $executed = [];

        $this->assertSame(
            ['stock' => 'stock', 'broadcasts' => 'broadcasts', 'receipts' => 'receipts'],
            $runner->run($operations, 10),
        );
        $this->assertSame(['stock', 'broadcasts', 'receipts'], $executed);
        $executed = [];

        $this->assertSame(
            ['receipts' => 'receipts', 'stock' => 'stock', 'broadcasts' => 'broadcasts'],
            $runner->run($operations, 10),
        );
        $this->assertSame(['receipts', 'stock', 'broadcasts'], $executed);
    }

    public function test_losing_the_cursor_rediscovers_all_stages(): void
    {
        $time = 0.0;
        $runner = new TelegramOutboundRecoveryStageRunner(function () use (&$time): float {
            return $time;
        });
        $operations = [
            'receipts' => function () use (&$time): int {
                $time += 2;

                return 3;
            },
            'broadcasts' => fn (): int => 9,
        ];

        $runner->run($operations, 1);
        Cache::flush();
        $this->assertSame(['receipts' => 3, 'broadcasts' => 9], $runner->run($operations, 10));
    }

    public function test_a_new_sweep_does_not_inherit_failures_from_the_previous_sweep(): void
    {
        $runner = new TelegramOutboundRecoveryStageRunner;
        $runner->run(['receipts' => fn () => throw new RuntimeException('statement timeout')], 10);

        $this->assertSame(['receipts' => 3], $runner->run(['receipts' => fn (): int => 3], 10));
        $this->assertFalse($runner->failed());
        $this->assertSame([], $runner->failures());
    }

    public static function noWorkInvocations(): array
    {
        return [
            'no stages' => [false, 10.0],
            'no budget' => [true, 0.0],
        ];
    }

    #[DataProvider('noWorkInvocations')]
    public function test_an_invocation_without_work_clears_previous_failure_state(bool $includeStage, float $budget): void
    {
        $runner = new TelegramOutboundRecoveryStageRunner;
        $runner->run(['receipts' => fn () => throw new RuntimeException('statement timeout')], 10);
        $this->assertTrue($runner->failed());

        $operations = $includeStage ? ['receipts' => fn (): int => 3] : [];

        $this->assertSame([], $runner->run($operations, $budget));
        $this->assertFalse($runner->failed());
        $this->assertSame([], $runner->failures());
    }
}
