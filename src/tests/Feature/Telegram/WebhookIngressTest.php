<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\TelegramBotTestSupport;

class WebhookIngressTest extends TelegramBotTestSupport
{
    public function test_webhook_secret_stores_update_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(1, '/start'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(2, '/start'), 'bad')->assertForbidden();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatched(ProcessTelegramUpdate::class);
    }

    public function test_duplicate_webhook_post_dispatches_only_once_and_invalid_payload_is_rejected(): void
    {
        Bus::fake();
        $payload = $this->messageUpdate(101, '/start');

        $this->postTelegramWebhook($payload)->assertOk();
        $this->postTelegramWebhook($payload)->assertOk();
        $this->postTelegramWebhook(['update_id' => 102, 'edited_message' => []])->assertUnprocessable();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatchedTimes(ProcessTelegramUpdate::class, 1);
    }

    public function test_duplicate_update_is_not_processed_twice(): void
    {
        $this->fakeTelegramHttp();

        $payload = $this->messageUpdate(10, '/start');
        $this->postTelegramWebhook($payload)->assertOk();
        $processedAt = TelegramUpdate::first()->processed_at;
        $this->postTelegramWebhook($payload)->assertOk();

        $this->assertNotNull($processedAt);
        $this->assertEquals($processedAt, TelegramUpdate::first()->processed_at);
        Http::assertSentCount(2);
    }

    public function test_duplicate_direct_job_does_not_send_twice(): void
    {
        $this->fakeTelegramHttp();
        $update = TelegramUpdate::create(['update_id' => 103, 'payload' => $this->messageUpdate(103, '/start'), 'created_at' => now()]);

        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
        $processedAt = $update->fresh()->processed_at;
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        $this->assertNotNull($processedAt);
        $this->assertEquals($processedAt, $update->fresh()->processed_at);
        Http::assertSentCount(2);
    }

    public function test_telegram_webhook_is_rate_limited_by_user(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(120, '/start'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(121, '/start'))->assertTooManyRequests();
    }

    public function test_dogfood_timing_is_disabled_by_default(): void
    {
        Log::spy();
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(130, '/start'))->assertOk();

        Log::shouldNotHaveReceived('info');
    }

    public function test_dogfood_timing_can_use_a_configured_non_file_log_channel(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.dogfood_timing.log_channel', 'stderr');
        $logger = Mockery::mock();

        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('dogfood.test_event', ['status' => 'ok']);
        Log::shouldReceive('info')->never();

        DogfoodTiming::log('dogfood.test_event', ['status' => 'ok']);
    }

    public function test_enabled_dogfood_timing_logs_safe_webhook_job_and_outbound_context(): void
    {
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use (&$logs) {
            $logs[] = [$event, $context];

            return true;
        });
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(131, '/start', from: ['id' => 987654], chatId: 123456))->assertOk();

        $this->assertLog($logs, 'dogfood.telegram.webhook_ack', fn (array $context) => $context['update_id'] === 131
            && array_key_exists('ack_duration_ms', $context)
            && array_key_exists('job_enqueued_at', $context)
            && $context['duplicate'] === false
        );
        $this->assertLog($logs, 'dogfood.telegram.job_picked_up', fn (array $context) => array_key_exists('job_picked_up_at', $context)
            && array_key_exists('job_enqueued_at', $context)
        );
        $this->assertLog($logs, 'dogfood.telegram.handler_finished', fn (array $context) => array_key_exists('handler_duration_ms', $context)
            && array_key_exists('db_query_count', $context)
            && array_key_exists('memory_before_bytes', $context)
            && array_key_exists('memory_after_bytes', $context)
        );
        $this->assertLog($logs, 'dogfood.outbound_http', fn (array $context) => $context['provider'] === 'telegram'
            && $context['operation'] === 'sendMessage'
            && array_key_exists('status', $context)
            && array_key_exists('duration_ms', $context)
        );

        $joined = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('123456', $joined);
        $this->assertStringNotContainsString('987654', $joined);
        $this->assertStringNotContainsString('bot-token', $joined);
        $this->assertStringNotContainsString('pakasir-key', $joined);
        $this->assertStringNotContainsString('/start', $joined);
    }

    private function assertLog(array $logs, string $event, callable $predicate): void
    {
        foreach ($logs as [$loggedEvent, $context]) {
            if ($loggedEvent === $event && $predicate($context)) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("Expected log [{$event}] was not emitted with matching context.");
    }
}
