<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class AdminMenuTest extends TelegramBotTestSupport
{
    public function test_configured_admin_telegram_user_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(301, '/menu', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && str_contains(json_encode($request['reply_markup']), 'Admin Dashboard')
            && str_contains(json_encode($request['reply_markup']), '/admin'));
    }

    public function test_allowed_admin_chat_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(302, '/menu', ['id' => 111], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && str_contains(json_encode($request['reply_markup']), 'Admin Dashboard')
            && str_contains(json_encode($request['reply_markup']), '/admin'));
    }

    public function test_non_admin_user_does_not_receive_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(303, '/menu', ['id' => 111], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && ($request['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Marketplace'
            && ($request['reply_markup']['keyboard'][0][1]['text'] ?? null) === '📦 Pesanan'
            && ! str_contains(json_encode($request['reply_markup']), 'Admin Dashboard'));
    }
}
