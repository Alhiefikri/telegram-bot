<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramOutboundRecoveryStageRunner;
use RuntimeException;
use Tests\TestCase;

class TelegramOutboundRecoveryStageRunnerTest extends TestCase
{
    public function test_a_failed_stage_does_not_stop_later_stages(): void
    {
        $runner = new TelegramOutboundRecoveryStageRunner;
        $executed = [];

        $failed = $runner->run('stock_notification', function () use (&$executed): void {
            $executed[] = 'stock_notification';
            throw new RuntimeException('statement timeout');
        });
        $result = $runner->run('order_lifecycle', function () use (&$executed): int {
            $executed[] = 'order_lifecycle';

            return 7;
        });

        $this->assertNull($failed);
        $this->assertSame(7, $result);
        $this->assertSame(['stock_notification', 'order_lifecycle'], $executed);
        $this->assertTrue($runner->failed());
        $this->assertSame(['stock_notification' => RuntimeException::class], $runner->failures());
    }

    public function test_a_sweep_without_failures_is_not_marked_as_failed(): void
    {
        $runner = new TelegramOutboundRecoveryStageRunner;

        $this->assertSame(3, $runner->run('healthy', fn (): int => 3));
        $this->assertFalse($runner->failed());
        $this->assertSame([], $runner->failures());
    }
}
