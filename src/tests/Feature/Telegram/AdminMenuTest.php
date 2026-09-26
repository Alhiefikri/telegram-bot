<?php

namespace Tests\Feature\Telegram;

use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramBroadcast;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBroadcastDrafts;
use App\Services\Telegram\TelegramBroadcastIntents;
use App\Services\Telegram\TelegramBroadcastService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class AdminMenuTest extends TelegramBotTestSupport
{
    public function test_configured_admin_telegram_user_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(301, '/start', ['id' => 999], 12345))->assertOk();

        $home = $this->homeRequest(12345);
        $keyboard = $home->data()['reply_markup']['inline_keyboard'];
        $this->assertSame('🛠️ Admin Dashboard', $keyboard[3][0]['text'] ?? null);
        $this->assertStringStartsWith('https://store.example/admin?v=', (string) ($keyboard[3][0]['web_app']['url'] ?? ''));
        $this->assertSame('📊 Statistik', $keyboard[4][0]['text'] ?? null);
        $this->assertSame('statistik_harian', $keyboard[4][0]['callback_data'] ?? null);
        $encoded = json_encode($home->data()['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('📊 Statistik Harian', $encoded);
        $this->assertStringNotContainsString('☰ Menu', $encoded);
        $this->assertStringNotContainsString('Akun Saya', $encoded);
        $this->assertArrayNotHasKey('keyboard', $home->data()['reply_markup']);
    }

    public function test_configured_admin_direct_chat_receives_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin?source=telegram');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(302, '/start', ['id' => 999], 999))->assertOk();

        $home = $this->homeRequest(999);
        $keyboard = $home->data()['reply_markup']['inline_keyboard'];
        $this->assertSame('🛠️ Admin Dashboard', $keyboard[3][0]['text'] ?? null);
        $this->assertStringStartsWith('https://store.example/admin?source=telegram&v=', (string) ($keyboard[3][0]['web_app']['url'] ?? ''));
        $this->assertSame('📊 Statistik', $keyboard[4][0]['text'] ?? null);
        $this->assertSame('statistik_harian', $keyboard[4][0]['callback_data'] ?? null);
        $encoded = json_encode($home->data()['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('☰ Menu', $encoded);
        $this->assertStringNotContainsString('Akun Saya', $encoded);
    }

    public function test_ngrok_admin_dashboard_button_is_an_inline_url_and_typed_label_sends_url_link(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://substandard-miguelina-soberingly.ngrok-free.dev/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(308, '/start', ['id' => 999], 12345))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(309, '🛠️ Admin Dashboard', ['id' => 999], 12345))->assertOk();

        $button = $this->homeRequest(12345)->data()['reply_markup']['inline_keyboard'][3][0] ?? [];
        $this->assertSame('🛠️ Admin Dashboard', $button['text'] ?? null);
        $this->assertStringStartsWith('https://substandard-miguelina-soberingly.ngrok-free.dev/admin?v=', (string) ($button['url'] ?? ''));
        $this->assertArrayNotHasKey('web_app', $button);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['text'] === 'Buka dashboard admin:'
            && str_starts_with((string) ($request['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''), 'https://substandard-miguelina-soberingly.ngrok-free.dev/admin?v=')
            && ! array_key_exists('web_app', $request['reply_markup']['inline_keyboard'][0][0] ?? []));
    }

    public function test_local_admin_url_does_not_send_web_app_button(): void
    {
        config()->set('app.url', 'http://localhost');
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'http://localhost/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(306, '/start', ['id' => 999], 12345))->assertOk();

        $encoded = json_encode($this->homeRequest(12345)->data()['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Lihat Produk', $encoded);
        $this->assertStringContainsString('📊 Statistik', $encoded);
        $this->assertStringNotContainsString('Admin Dashboard', $encoded);
        $this->assertStringNotContainsString('web_app', $encoded);
        $this->assertStringNotContainsString('localhost', $encoded);
    }

    public function test_loopback_app_url_does_not_send_invalid_web_app_payload(): void
    {
        config()->set('app.url', 'http://127.0.0.1');
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', null);
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        URL::forceRootUrl('http://127.0.0.1');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        try {
            $this->postTelegramWebhook($this->messageUpdate(307, '/start', ['id' => 999], 12345))->assertOk();

            $encoded = json_encode($this->homeRequest(12345)->data()['reply_markup'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('web_app', $encoded);
            $this->assertStringNotContainsString('☰ Menu', $encoded);
            $this->assertStringNotContainsString('Akun Saya', $encoded);
            $this->assertStringNotContainsString('127.0.0.1', $encoded);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    public function test_non_admin_user_does_not_receive_admin_dashboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(303, '/start', ['id' => 111], 12345))->assertOk();

        $home = $this->homeRequest(12345);
        $keyboard = $home->data()['reply_markup']['inline_keyboard'];
        $this->assertSame('🛍️ Lihat Produk', $keyboard[0][0]['text'] ?? null);
        $this->assertSame('💰 Saldo', $keyboard[1][0]['text'] ?? null);
        $this->assertSame('📦 Pesanan', $keyboard[1][1]['text'] ?? null);
        $this->assertCount(3, $keyboard);
        $encoded = json_encode($home->data()['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Admin Dashboard', $encoded);
        $this->assertStringNotContainsString('📊 Statistik', $encoded);
        $this->assertStringNotContainsString('☰ Menu', $encoded);
        $this->assertStringNotContainsString('Akun Saya', $encoded);
    }

    public function test_admin_statistics_button_opens_three_period_report(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(318, '/start', ['id' => 999], 999))->assertOk();

        $home = $this->homeRequest(999);
        $button = collect($home->data()['reply_markup']['inline_keyboard'])
            ->flatten(1)
            ->firstWhere('callback_data', 'statistik_harian');
        $this->assertNotNull($button, 'The admin home keyboard must expose the statistics shortcut.');
        $this->assertSame('📊 Statistik', $button['text']);

        $this->postTelegramWebhook(
            $this->callbackUpdate(319, 'home-stats', (string) $button['callback_data'], ['id' => 999], 999),
        )->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'home-stats');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), '☀️ Hari Ini')
            && str_contains((string) ($request['text'] ?? ''), '🗓 Minggu Ini')
            && str_contains((string) ($request['text'] ?? ''), '📅 Bulan Ini'));
    }

    private function homeRequest(int $chatId): Request
    {
        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);

        return $requests->sole()[0];
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

    public function test_admin_photo_broadcast_keeps_the_largest_photo_reference_and_shows_a_separate_exact_preview(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $payload = $this->messageUpdate(310, '', ['id' => 999], 999);
        $payload['message']['caption'] = "  \n /broadcast\nPromo foto literal";
        $payload['message']['photo'] = [
            ['file_id' => 'small-photo-id', 'width' => 100, 'height' => 100, 'file_size' => 1000],
            ['file_id' => 'large-photo-id', 'width' => 1200, 'height' => 800, 'file_size' => 5000],
        ];
        unset($payload['message']['text']);

        $this->postTelegramWebhook($payload)->assertOk();

        $stored = TelegramUpdate::firstOrFail()->payload['message'];
        $this->assertSame('photo', $stored['media_kind']);
        $this->assertSame([
            ['file_id' => 'small-photo-id', 'file_size' => 1000, 'width' => 100, 'height' => 100],
            ['file_id' => 'large-photo-id', 'file_size' => 5000, 'width' => 1200, 'height' => 800],
        ], $stored['photo']);

        $previewPhoto = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->first()[0];
        $this->assertSame('large-photo-id', $previewPhoto['photo']);
        $this->assertSame('Promo foto literal', $previewPhoto['caption']);

        $controlPanel = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'))->last()[0];
        $this->assertStringNotContainsString('Promo foto literal', $controlPanel['text']);
        $this->assertNotEmpty($controlPanel['reply_markup']['inline_keyboard'] ?? []);

        $draft = app(TelegramBroadcastDrafts::class)->get(Customer::query()->where('telegram_id', 999)->firstOrFail());
        $this->assertSame('Promo foto literal', $draft['message']);
        $this->assertSame('large-photo-id', $draft['photo_file_id']);
        $this->assertSame(0, TelegramBroadcast::query()->count());
    }

    public function test_broadcast_rejects_albums_and_nonphoto_media_before_creating_a_draft(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $album = $this->messageUpdate(321, '', ['id' => 999], 999);
        $album['message']['caption'] = '/broadcast Album promo';
        $album['message']['media_group_id'] = 'album-321';
        $album['message']['photo'] = [
            ['file_id' => 'album-photo', 'width' => 800, 'height' => 600],
        ];
        unset($album['message']['text']);

        $video = $this->messageUpdate(322, '', ['id' => 999], 999);
        $video['message']['caption'] = '/broadcast Video promo';
        $video['message']['video'] = ['file_id' => 'video-file-id', 'width' => 800, 'height' => 600];
        unset($video['message']['text']);

        $unknown = $this->messageUpdate(323, '', ['id' => 999], 999);
        $unknown['message']['caption'] = '/broadcast Future media promo';
        $unknown['message']['future_media'] = ['file_id' => 'future-media-file-id'];
        unset($unknown['message']['text']);

        $this->postTelegramWebhook($album)->assertOk();
        $this->postTelegramWebhook($video)->assertOk();
        $this->postTelegramWebhook($unknown)->assertOk();

        $stored = TelegramUpdate::query()->orderBy('update_id')->get()->map(fn (TelegramUpdate $update): array => $update->payload['message'])->all();
        $this->assertSame('photo', $stored[0]['media_kind']);
        $this->assertSame('album-321', $stored[0]['media_group_id']);
        $this->assertSame('video', $stored[1]['media_kind']);
        $this->assertStringNotContainsString('video-file-id', json_encode($stored[1], JSON_THROW_ON_ERROR));
        $this->assertSame('unsupported', $stored[2]['media_kind']);
        $this->assertStringNotContainsString('future-media-file-id', json_encode($stored[2], JSON_THROW_ON_ERROR));
        $this->assertNull(app(TelegramBroadcastDrafts::class)->get(Customer::query()->where('telegram_id', 999)->firstOrFail()));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        Http::assertNotSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'));
    }

    public function test_photo_caption_contract_allows_photo_only_rejects_overlong_caption_and_never_arms_a_later_photo(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 999]);

        $photoOnly = $this->messageUpdate(323, '', ['id' => 999], 999);
        $photoOnly['message']['caption'] = '/broadcast';
        $photoOnly['message']['photo'] = [
            ['file_id' => 'photo-only-file-id', 'width' => 800, 'height' => 800],
        ];
        unset($photoOnly['message']['text']);

        $tooLong = $this->messageUpdate(324, '', ['id' => 999], 999);
        $tooLong['message']['caption'] = '/broadcast '.str_repeat('x', 1025);
        $tooLong['message']['photo'] = [
            ['file_id' => 'too-long-file-id', 'width' => 800, 'height' => 800],
        ];
        unset($tooLong['message']['text']);

        $unarmed = $this->messageUpdate(325, '', ['id' => 999], 999);
        $unarmed['message']['caption'] = 'ordinary photo caption';
        $unarmed['message']['photo'] = [
            ['file_id' => 'unarmed-file-id', 'width' => 800, 'height' => 800],
        ];
        unset($unarmed['message']['text']);

        $this->postTelegramWebhook($photoOnly)->assertOk();
        $this->assertSame('', app(TelegramBroadcastDrafts::class)->get($customer)['message']);
        $photoPreview = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $this->assertSame('photo-only-file-id', $photoPreview['photo']);
        $this->assertArrayNotHasKey('caption', $photoPreview);

        $this->postTelegramWebhook($tooLong)->assertOk();
        $this->postTelegramWebhook($unarmed)->assertOk();

        $this->assertSame('', app(TelegramBroadcastDrafts::class)->get($customer)['message']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && ($request['text'] ?? null) === 'Caption foto broadcast maksimal 1024 karakter.');
        $photoRequests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photoRequests);
    }

    public function test_photo_confirmation_persists_the_literal_caption_and_product_action_in_the_durable_intent(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $product = Product::factory()->create([
            'name' => 'Photo Product',
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $intents = app(TelegramBroadcastIntents::class);

        $intents->handleCommand($customer, '999', '/broadcast Caption from caller', [
            'media_kind' => 'photo',
            'caption' => '/broadcast Caption from photo',
            'photo' => [
                ['file_id' => 'photo-small', 'width' => 100, 'height' => 100],
                ['file_id' => 'photo-large', 'width' => 1000, 'height' => 1000],
            ],
        ]);
        $preview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'))->last()[0];
        $pick = $preview['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $intents->handleCallback($customer, '999', 'photo-pick', $pick);
        $picker = Http::recorded(fn ($request): bool => ($request['text'] ?? null) === 'Pilih produk untuk tombol pembelian broadcast.')->last()[0];
        $select = $picker['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $intents->handleCallback($customer, '999', 'photo-select', $select);
        $selectedPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Tombol produk: Photo Product'))->last()[0];
        $send = $selectedPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

        $intents->handleCallback($customer, '999', 'photo-send', $send);

        $broadcast = TelegramBroadcast::firstOrFail();
        $this->assertSame('Caption from photo', $broadcast->message);
        $this->assertSame('photo-large', $broadcast->photo_file_id);
        $this->assertSame('product:'.$product->id, $broadcast->reply_markup['id']['inline_keyboard'][0][0]['callback_data']);
        $this->assertNull(app(TelegramBroadcastDrafts::class)->get($customer));
        Queue::assertPushed(SendTelegramBroadcastBatch::class);
    }

    public function test_broadcast_command_is_private_only(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

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
        $preview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Promo terbatas'))->last()[0];
        $send = $preview['reply_markup']['inline_keyboard'][1][0]['callback_data'];
        Cache::forget('telegram:broadcast-draft:999');
        $this->postTelegramWebhook($this->callbackUpdate(314, 'expired-broadcast', $send, ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'expired-broadcast'
            && $request['text'] === 'Draft sudah kedaluwarsa.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Promo terbatas')
            && (string) ($request['chat_id'] ?? '') !== '999');
    }

    public function test_broadcast_preview_controls_are_bound_to_an_unpredictable_draft_identity(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $intents = app(TelegramBroadcastIntents::class);

        $intents->handleCommand($customer, '999', '/broadcast Preview me');

        $previewRequest = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Preview broadcast'))->last()[0];
        $buttons = $previewRequest['reply_markup']['inline_keyboard'];
        $callbacks = array_values(array_filter(array_map(
            fn (array $button): ?string => $button['callback_data'] ?? null,
            array_merge(...$buttons),
        )));

        $this->assertCount(3, $callbacks);
        $this->assertCount(count($callbacks), array_unique($callbacks));
        foreach ($callbacks as $callback) {
            $this->assertLessThanOrEqual(64, strlen($callback));
            $this->assertMatchesRegularExpression('/^broadcast:[a-z]+:[A-Za-z0-9]{16}(?::[1-9][0-9]*)?$/', $callback);
        }
    }

    public function test_product_picker_and_confirmation_keep_the_same_draft_identity_and_localized_action(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $product = Product::factory()->create([
            'name' => 'Premium Product',
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $intents = app(TelegramBroadcastIntents::class);

        $intents->handleCommand($customer, '999', '/broadcast Literal product note');
        $preview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Literal product note'))->last()[0];
        $pick = $preview['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        preg_match('/^broadcast:pick:([A-Za-z0-9]{16}):1$/', $pick, $pickMatches);
        $draftId = $pickMatches[1] ?? null;
        $this->assertNotNull($draftId);

        $intents->handleCallback($customer, '999', 'pick-product', $pick);
        $picker = Http::recorded(fn ($request): bool => ($request['text'] ?? null) === 'Pilih produk untuk tombol pembelian broadcast.')->last()[0];
        $select = $picker['reply_markup']['inline_keyboard'][0][0]['callback_data'];

        $this->assertLessThanOrEqual(64, strlen($select));
        $this->assertMatchesRegularExpression('/^broadcast:select:'.preg_quote($draftId, '/').':'.$product->id.'$/', $select);

        $intents->handleCallback($customer, '999', 'select-product', $select);
        $selectedPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Tombol produk: Premium Product'))->last()[0];
        $send = $selectedPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

        $this->assertLessThanOrEqual(64, strlen($send));
        $this->assertSame('broadcast:send:'.$draftId, $send);

        $intents->handleCallback($customer, '999', 'send-product', $send);

        $broadcast = TelegramBroadcast::query()->firstOrFail();
        $this->assertSame('Literal product note', $broadcast->message);
        $this->assertSame('product:'.$product->id, $broadcast->reply_markup['id']['inline_keyboard'][0][0]['callback_data']);
        Queue::assertPushedTimes(SendTelegramBroadcastBatch::class, 1);
    }

    public function test_stale_confirmation_cannot_send_the_replacement_draft(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $intents = app(TelegramBroadcastIntents::class);

        $intents->handleCommand($customer, '999', '/broadcast First draft');
        $firstPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'First draft'))->last()[0];
        $firstSend = $firstPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

        $intents->handleCommand($customer, '999', '/broadcast Replacement draft');
        $secondPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Replacement draft'))->last()[0];
        $secondSend = $secondPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

        $this->assertNotSame($firstSend, $secondSend);
        $intents->handleCallback($customer, '999', 'stale-confirmation', $firstSend);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'stale-confirmation'
            && str_contains((string) ($request['text'] ?? ''), 'tidak berlaku'));
        $this->assertSame(0, TelegramBroadcast::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame('Replacement draft', app(TelegramBroadcastDrafts::class)->get($customer)['message']);
    }

    public function test_replacing_a_broadcast_draft_preserves_the_original_expiry_for_stale_and_expired_confirmation(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $intents = app(TelegramBroadcastIntents::class);
        $createdAt = Carbon::parse('2026-08-13 10:00:00');
        Carbon::setTestNow($createdAt);

        try {
            $intents->handleCommand($customer, '999', '/broadcast First draft');
            $firstDraft = app(TelegramBroadcastDrafts::class)->get($customer);
            $firstPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'First draft'))->last()[0];
            $firstSend = $firstPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

            Carbon::setTestNow($createdAt->copy()->addMinutes(14));
            $intents->handleCommand($customer, '999', '/broadcast Replacement draft');
            $replacementDraft = app(TelegramBroadcastDrafts::class)->get($customer);
            $secondPreview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Replacement draft'))->last()[0];
            $secondSend = $secondPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

            $this->assertNotNull($firstDraft);
            $this->assertNotNull($replacementDraft);
            $this->assertSame($firstDraft['expires_at'], $replacementDraft['expires_at']);

            $intents->handleCallback($customer, '999', 'stale-before-expiry', $firstSend);
            Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && $request['callback_query_id'] === 'stale-before-expiry'
                && str_contains((string) ($request['text'] ?? ''), 'tidak berlaku'));

            Carbon::setTestNow($createdAt->copy()->addMinutes(15)->addSecond());
            $intents->handleCallback($customer, '999', 'expired-replacement', $secondSend);

            Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && $request['callback_query_id'] === 'expired-replacement'
                && $request['text'] === 'Draft sudah kedaluwarsa.');
            $this->assertSame(0, TelegramBroadcast::query()->count());
            Queue::assertNothingPushed();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_confirmation_persistence_failure_leaves_the_draft_retryable(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 999]);
        $service = Mockery::mock(TelegramBroadcastService::class);
        $service->shouldReceive('queueAdminBroadcast')
            ->once()
            ->andThrow(new \RuntimeException('persistence failed'));
        $this->app->instance(TelegramBroadcastService::class, $service);
        $intents = app(TelegramBroadcastIntents::class);

        $intents->handleCommand($customer, '999', '/broadcast Retry me');
        $preview = Http::recorded(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Retry me'))->last()[0];
        $send = $preview['reply_markup']['inline_keyboard'][1][0]['callback_data'];

        $intents->handleCallback($customer, '999', 'persistence-failure', $send);

        $draft = app(TelegramBroadcastDrafts::class)->get($customer);
        $this->assertIsArray($draft);
        $this->assertSame('Retry me', $draft['message']);
        $this->assertNull(TelegramBroadcast::query()->first());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'gagal disimpan'));
    }

    public function test_start_welcome_shows_mini_app_dashboard_only_to_admin(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(304, '/start', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'selamat datang di LHIE STORE')
            && ($request->data()['reply_markup']['inline_keyboard'][3][0]['text'] ?? null) === '🛠️ Admin Dashboard'
            && str_starts_with((string) ($request->data()['reply_markup']['inline_keyboard'][3][0]['web_app']['url'] ?? ''), 'https://store.example/admin?v=')
            && ($request->data()['reply_markup']['inline_keyboard'][4][0]['text'] ?? null) === '📊 Statistik'
            && ($request->data()['reply_markup']['inline_keyboard'][4][0]['callback_data'] ?? null) === 'statistik_harian'
            && ! str_contains(json_encode($request->data()['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '📊 Statistik Harian'));
    }

    public function test_start_welcome_hides_mini_app_dashboard_when_admin_url_is_local(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'http://localhost/admin');
        $this->fakeTelegramHttp();
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(308, '/start', ['id' => 999], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'selamat datang di LHIE STORE')
            && str_contains(json_encode($request->data()['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'Lihat Produk')
            && str_contains(json_encode($request->data()['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '📊 Statistik')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'Mini App Dashboard')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'web_app')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'localhost'));
    }

    public function test_start_welcome_hides_mini_app_dashboard_from_non_admin(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.admin_telegram_chat_id', '999');
        $this->fakeTelegramHttp();
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(305, '/start', ['id' => 111], 12345))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'selamat datang di LHIE STORE')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? []), 'Mini App Dashboard')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? []), '/admin'));
    }

    public function test_start_welcome_hides_mini_app_dashboard_in_group_chat(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('services.telegram.mini_app_url', 'https://store.example/admin');
        config()->set('services.telegram.welcome_banner_url', 'telegram-banner-file-id');
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(318, '/start', ['id' => 999], -100123, 'group'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? []), 'Mini App Dashboard')
            && ! str_contains(json_encode($request->data()['reply_markup'] ?? []), 'web_app'));
    }

    public function test_non_admin_cannot_trigger_forged_daily_stats_callback(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);

        $this->postTelegramWebhook($this->callbackUpdate(309, 'forged-stats', 'statistik_harian', ['id' => 111], 111))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'forged-stats'
            && $request['text'] === 'Akses ditolak.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '📊 Pertumbuhan Store'));
    }

    public function test_admin_can_trigger_legacy_daily_stats_callback_in_private_chat(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        config()->set('app.url', 'https://admin.example.test');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(320, 'legacy-stats', 'statistik_harian', ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'legacy-stats');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '999'
            && str_contains((string) $request['text'], '📊 Pertumbuhan Store'));
    }

    public static function statisticsLabels(): array
    {
        return [
            'current label' => ['📊 Statistik'],
            'legacy label' => ['📊 Statistik Harian'],
        ];
    }

    #[DataProvider('statisticsLabels')]
    public function test_admin_can_open_store_growth_stats_from_current_and_legacy_labels(string $label): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(315, $label, ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '999'
            && str_contains($request['text'], '📊 Pertumbuhan Store'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Aku belum paham perintah itu'));
    }

    public function test_store_growth_stats_are_private_chat_only(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook(
            $this->messageUpdate(319, '📊 Statistik', ['id' => 999], -100123, 'group')
        )->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains((string) $request['text'], 'Lanjut di chat pribadi'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '📊 Pertumbuhan Store'));
    }

    public function test_admin_can_open_product_stock_overview_from_the_reply_keyboard_button(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Gemini Pro', 'stock' => 12, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Netflix Premium', 'stock' => 3, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Canva Pro', 'stock' => 1, 'reserved_stock' => 1]);
        $credentialProduct = Product::factory()->create(['name' => 'ChatGPT Plus', 'stock' => 5, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $credentialProduct->id, 'secret' => 'akun-gpt@example.test | password: Secret-123']);
        Product::factory()->create(['name' => 'Produk Mati', 'is_active' => false, 'stock' => 9]);

        $this->postTelegramWebhook($this->messageUpdate(316, '📦 Cek Stok', ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '999'
            && str_contains($request['text'], '📦 Stok Produk')
            && str_contains($request['text'], '✅ Gemini Pro: 12')
            && str_contains($request['text'], '⚠️ Netflix Premium: 2 (1 direservasi)')
            && str_contains($request['text'], '❌ Canva Pro: habis (1 direservasi)')
            && str_contains($request['text'], '⚠️ ChatGPT Plus: 1')
            && str_contains($request['text'], 'Total produk aktif: 4')
            && ! str_contains($request['text'], 'Produk Mati')
            && strpos($request['text'], 'Canva Pro') < strpos($request['text'], 'Gemini Pro'));
    }

    public function test_admin_can_open_stock_overview_with_english_command_alias(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Canva Pro', 'stock' => 4, 'reserved_stock' => 1]);

        $this->postTelegramWebhook($this->messageUpdate(320, '/stock', ['id' => 999], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '999'
            && str_contains($request['text'], '📦 Stok Produk')
            && str_contains($request['text'], 'Canva Pro: 3'));
    }

    public function test_english_stock_command_stays_private_chat_only(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);
        Product::factory()->create(['name' => 'Canva Pro', 'stock' => 4, 'reserved_stock' => 1]);

        $this->postTelegramWebhook(
            $this->messageUpdate(321, '/stock', ['id' => 999], -100123, 'group')
        )->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'Lanjut di chat pribadi'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Stok Produk'));
    }

    public function test_non_admin_cannot_open_product_stock_overview(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);
        Product::factory()->create(['name' => 'Gemini Pro', 'stock' => 12, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(317, '📦 Cek Stok', ['id' => 111], 111))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '111'
            && $request['text'] === 'Akses ditolak.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Stok Produk'));
    }

    public function test_daily_stats_callback_from_group_is_denied_before_sending_stats(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 999, 'locale' => 'id']);

        $this->postTelegramWebhook($this->callbackUpdate(310, 'group-stats', 'statistik_harian', ['id' => 999], -100123, 'group'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'group-stats'
            && $request['text'] === 'Demi keamanan, lanjutkan di chat pribadi dengan bot.');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '📊 Pertumbuhan Store'));
    }
}
