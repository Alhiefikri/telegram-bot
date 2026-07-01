<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookSetupCommandTest extends TestCase
{
    public function test_it_sets_telegram_webhook_from_app_url_and_secret(): void
    {
        config()->set('app.url', 'https://store.example.com');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'webhook-secret');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        Http::fake([
            'https://api.telegram.org/botbot-token/setWebhook' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->artisan('telegram:webhook:set')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/botbot-token/setWebhook'
            && $request['url'] === 'https://store.example.com/api/webhook/telegram'
            && $request['secret_token'] === 'webhook-secret'
            && $request['allowed_updates'] === ['message', 'callback_query']);
    }

    public function test_it_skips_when_app_url_is_not_public_https(): void
    {
        config()->set('app.url', 'http://127.0.0.1:8000');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'webhook-secret');

        Http::fake();

        $this->artisan('telegram:webhook:set')->assertSuccessful();

        Http::assertNothingSent();
    }
}
