<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendBalanceOperationsAlert;
use App\Models\TelegramBroadcast;
use App\Models\TelegramOutboundEffect;
use App\Services\Operations\BalanceOperationsAlertDelivery;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundRecoveryStageRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_budget_exhaustion_defers_later_work_until_the_next_command_without_reporting_failure(): void
    {
        $time = 0.0;
        $this->app->instance(TelegramOutboundRecoveryStageRunner::class, new TelegramOutboundRecoveryStageRunner(
            function () use (&$time): float {
                return $time;
            },
        ));
        $this->mock(StockNotificationDeliveryPipeline::class)
            ->shouldReceive('reconcile')
            ->andReturnUsing(function () use (&$time): int {
                $time += 3;

                return 0;
            });
        $broadcastSweeps = 0;
        $this->mock(TelegramBroadcastService::class)
            ->shouldReceive('recoverableBroadcastsQuery')
            ->once()
            ->andReturnUsing(function () use (&$broadcastSweeps) {
                $broadcastSweeps++;

                return TelegramBroadcast::query();
            });

        $this->artisan('telegram:recover-outbound', ['--max-seconds' => 5])->assertSuccessful();
        $this->assertSame(0, $broadcastSweeps);

        $this->artisan('telegram:recover-outbound', ['--max-seconds' => 5])->assertSuccessful();
        $this->assertSame(1, $broadcastSweeps);
    }

    public function test_resumed_recovery_and_duplicate_wakeups_never_resend_an_ambiguous_delivery(): void
    {
        Queue::fake();
        config()->set('services.telegram.token', 'test-token');
        $attributes = [
            'effect_type' => TelegramOutboundEffectType::BalanceOperationsAlert->value,
            'telegram_chat_id' => '900100200',
            'queue' => 'notifications',
            'outcome_metadata' => ['message' => 'Recovery proof'],
        ];
        $pending = TelegramOutboundEffect::factory()->create($attributes);
        $protected = collect([
            ['state' => TelegramOutboundEffectState::Unknown],
            ['state' => TelegramOutboundEffectState::ManualReview],
            ['state' => TelegramOutboundEffectState::Sent],
            ['state' => TelegramOutboundEffectState::Sending, 'lease_until' => now()->addHour()],
            ['state' => TelegramOutboundEffectState::Failed, 'last_error_code' => 'telegram_http_403'],
            ['state' => TelegramOutboundEffectState::Failed, 'next_attempt_at' => now()->addHour()],
        ])->map(fn (array $state) => TelegramOutboundEffect::factory()->create(array_merge($attributes, $state)));
        $before = $protected->map(fn ($effect) => $effect->fresh()->getAttributes())->all();
        $requests = 0;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$requests): never {
                $requests++;
                throw new ConnectionException('ACK lost after Telegram accepted the message');
            },
        ]);

        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $wakeups = Queue::pushed(SendBalanceOperationsAlert::class);
        $this->assertCount(1, $wakeups);
        $job = $wakeups->sole();
        $delivery = app(BalanceOperationsAlertDelivery::class);
        $job->handle($delivery);
        $job->handle($delivery);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $pending->fresh()->state);
        $this->assertSame(1, $pending->fresh()->attempts);
        Queue::fake();
        $this->artisan('telegram:recover-outbound')->assertSuccessful();

        Queue::assertNotPushed(SendBalanceOperationsAlert::class);
        $this->assertSame(1, $requests);
        $this->assertSame($before, $protected->map(fn ($effect) => $effect->fresh()->getAttributes())->all());
    }
}
