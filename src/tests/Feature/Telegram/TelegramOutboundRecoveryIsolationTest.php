<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramBroadcast;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramBroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TelegramOutboundRecoveryIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failing_stage_does_not_abort_later_recovery_stages(): void
    {
        $this->mock(StockNotificationDeliveryPipeline::class)
            ->shouldReceive('reconcile')
            ->once()
            ->andThrow(new RuntimeException('database statement timeout'));

        $broadcasts = $this->mock(TelegramBroadcastService::class);
        $broadcasts->shouldReceive('recoverableBroadcastsQuery')
            ->once()
            ->andReturn(TelegramBroadcast::query());

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->expectsOutputToContain('completed with stage failures: reconcile_stock_notifications')
            ->assertFailed();
    }
}
