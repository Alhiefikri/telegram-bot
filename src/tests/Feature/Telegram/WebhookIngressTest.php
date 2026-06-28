<?php

namespace Tests\Feature\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        Http::assertSentCount(2);
    }

    public function test_telegram_webhook_is_rate_limited_by_user(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();

        $this->postTelegramWebhook($this->messageUpdate(120, '/start'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(121, '/start'))->assertTooManyRequests();
    }
}
