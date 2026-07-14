<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\TelegramBotTestSupport;

class AdminMenuTest extends TelegramBotTestSupport
{
    public function test_configured_admin_telegram_user_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(301, '/menu', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && ($request['reply_markup']['keyboard'][2][0]['text'] ?? null) === '🛠️ Admin Dashboard'
            && str_starts_with((string) ($request['reply_markup']['keyboard'][2][0]['web_app']['url'] ?? ''), 'https://store.example/admin?v=')
            && ! str_contains(json_encode($request['reply_markup']), '☰ Menu')
            && ! str_contains(json_encode($request['reply_markup']), 'Akun Saya'));
    }

    public function test_configured_admin_direct_chat_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin?source=telegram');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(302, '/menu', ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && ($request['reply_markup']['keyboard'][2][0]['text'] ?? null) === '🛠️ Admin Dashboard'
            && str_starts_with((string) ($request['reply_markup']['keyboard'][2][0]['web_app']['url'] ?? ''), 'https://store.example/admin?source=telegram&v=')
            && ! str_contains(json_encode($request['reply_markup']), '☰ Menu')
            && ! str_contains(json_encode($request['reply_markup']), 'Akun Saya'));
    }

    public function test_local_admin_url_does_not_send_web_app_button(): void
    {
        config()->set('app.url', 'http://localhost');
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'http://localhost/admin');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(306, '/menu', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && str_contains(json_encode($request['reply_markup']), 'Broadcast Info')
            && ! str_contains(json_encode($request['reply_markup']), '☰ Menu')
            && ! str_contains(json_encode($request['reply_markup']), 'Akun Saya')
            && ! str_contains(json_encode($request['reply_markup']), 'Admin Dashboard')
            && ! str_contains(json_encode($request['reply_markup']), 'web_app')
            && ! str_contains(json_encode($request['reply_markup']), 'localhost'));
    }

    public function test_loopback_app_url_does_not_send_invalid_web_app_payload(): void
    {
        config()->set('app.url', 'http://127.0.0.1');
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', null);
        URL::forceRootUrl('http://127.0.0.1');
        $this->fakeTelegramHttp();

        try {
            $this->postTelegramWebhook($this->messageUpdate(307, '/menu', ['id' => 999], 12345))->assertOk();

            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && str_contains($request['text'], 'Menu utama')
                && ! str_contains(json_encode($request['reply_markup']), 'web_app')
                && ! str_contains(json_encode($request['reply_markup']), '☰ Menu')
                && ! str_contains(json_encode($request['reply_markup']), 'Akun Saya')
                && ! str_contains(json_encode($request['reply_markup']), '127.0.0.1'));
        } finally {
            URL::forceRootUrl(null);
        }
    }

    public function test_non_admin_user_does_not_receive_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(303, '/menu', ['id' => 111], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'Menu utama')
            && ($request['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Lihat Produk'
            && ($request['reply_markup']['keyboard'][0][1]['text'] ?? null) === '📦 Pesanan'
            && ! str_contains(json_encode($request['reply_markup']), '☰ Menu')
            && ! str_contains(json_encode($request['reply_markup']), 'Akun Saya')
            && ! str_contains(json_encode($request['reply_markup']), 'Admin Dashboard'));
    }

    public function test_non_admin_cannot_create_a_broadcast_draft(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(311, '/broadcast Promo rahasia', ['id' => 111], 111))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '111'
            && $request['text'] === 'Akses admin diperlukan.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'));
    }

    public function test_broadcast_command_is_private_only(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(312, '/broadcast Promo grup', ['id' => 999], -100123, 'group'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'Lanjut di chat pribadi'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'));
    }

    public function test_expired_broadcast_draft_cannot_be_sent(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(313, '/broadcast Promo terbatas', ['id' => 999], 999))->assertOk();
        Cache::forget('telegram:broadcast-draft:999');
        $this->postTelegramWebhook($this->callbackUpdate(314, 'expired-broadcast', 'broadcast:send', ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'expired-broadcast'
            && $request['text'] === 'Draft sudah kedaluwarsa.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Promo terbatas')
            && (string) ($request['chat_id'] ?? '') !== '999');
    }

    public function test_start_welcome_shows_mini_app_dashboard_only_to_admin(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(304, '/start', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'welcome ke LHIE STORE')
            && ($request['reply_markup']['inline_keyboard'][1][0]['text'] ?? null) === '🌐 Mini App Dashboard'
            && str_starts_with((string) ($request['reply_markup']['inline_keyboard'][1][0]['web_app']['url'] ?? ''), 'https://store.example/admin?v='));
    }

    public function test_start_welcome_hides_mini_app_dashboard_when_admin_url_is_local(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'http://localhost/admin');
        $this->fakeTelegramHttp();
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(308, '/start', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'welcome ke LHIE STORE')
            && str_contains(json_encode($request['reply_markup']), 'Lihat Produk')
            && ! str_contains(json_encode($request['reply_markup']), 'Mini App Dashboard')
            && ! str_contains(json_encode($request['reply_markup']), 'web_app')
            && ! str_contains(json_encode($request['reply_markup']), 'localhost'));
    }

    public function test_start_welcome_hides_mini_app_dashboard_from_non_admin(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        $this->fakeTelegramHttp();
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(305, '/start', ['id' => 111], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains($request['text'], 'welcome ke LHIE STORE')
            && ! str_contains(json_encode($request['reply_markup']), 'Mini App Dashboard')
            && ! str_contains(json_encode($request['reply_markup']), '/admin'));
    }

    public function test_non_admin_cannot_trigger_forged_daily_stats_callback(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(309, 'forged-stats', 'statistik_harian', ['id' => 111], 111))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'forged-stats'
            && $request['text'] === 'Akses ditolak.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Statistik Hari Ini'));
    }

    public function test_daily_stats_callback_from_group_is_denied_before_sending_stats(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(310, 'group-stats', 'statistik_harian', ['id' => 999], -100123, 'group'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'group-stats'
            && $request['text'] === 'Demi keamanan, lanjutkan di chat pribadi dengan bot ya.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Statistik Hari Ini'));
    }
}
