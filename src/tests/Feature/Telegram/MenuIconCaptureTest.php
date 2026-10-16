<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramUiIcon;
use App\Services\Telegram\TelegramHtmlCaption;
use App\Services\Telegram\TelegramTranslationCatalog;
use App\Services\Telegram\TelegramUiIcons;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class MenuIconCaptureTest extends TelegramBotTestSupport
{
    private const EMOJI_ID = '5368324170671202286';

    public function test_admin_can_open_the_menu_icon_list(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);

        $this->postTelegramWebhook($this->callbackUpdate(50200, 'list', 'menuicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon Menu', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $callbacks = array_column($buttons, 'callback_data');
        $this->assertContains('menuicon:pick:'.$this->menuIcon('menu.products')->id, $callbacks);
        $this->assertContains('producticon:list', $callbacks);
        $this->assertContains('messageicon:list', $callbacks);
    }

    public function test_admin_can_open_the_message_copy_icon_list(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);

        $this->postTelegramWebhook($this->callbackUpdate(50201, 'list', 'messageicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon teks pesan', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $callbacks = array_column($buttons, 'callback_data');
        $icon = TelegramUiIcon::query()->where('key', 'message.payment_card')->sole();
        $this->assertContains('messageicon:pick:'.$icon->id, $callbacks);
    }

    public function test_success_icon_backfill_restores_the_missing_slot_in_the_message_icon_list(): void
    {
        TelegramUiIcon::query()->where('key', 'message.success')->delete();
        app(TelegramUiIcons::class)->messageCopyIcons();

        $migration = require database_path('migrations/2026_10_01_000002_restore_telegram_success_message_icon.php');
        $migration->up();

        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $this->postTelegramWebhook($this->callbackUpdate(50300, 'list', 'messageicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $icon = TelegramUiIcon::query()->where('key', 'message.success')->sole();
        $buttons = $this->assertValidInlineKeyboard((array) $this->sentEditRequest(777)['reply_markup']);
        $button = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'messageicon:pick:'.$icon->id);
        $this->assertNotNull($button);
        $this->assertSame('⬜ ✅ Berhasil', $button['text']);
        $this->assertSame('✅', $icon->source_emoji);
        $this->assertSame('✅ Berhasil', app(TelegramUiIcons::class)->renderLegacyIcons('✅ Berhasil'));
    }

    public function test_success_icon_backfill_preserves_an_existing_custom_icon(): void
    {
        $icon = $this->menuIcon('message.success');
        $icon->update(['fallback_emoji' => '🪙', 'custom_emoji_id' => self::EMOJI_ID]);
        $attributes = $icon->fresh()->getAttributes();

        $migration = require database_path('migrations/2026_10_01_000002_restore_telegram_success_message_icon.php');
        $migration->up();
        $migration->up();

        $this->assertSame($attributes, $icon->fresh()->getAttributes());
        $this->assertSame(
            '<tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji> Berhasil',
            app(TelegramUiIcons::class)->renderLegacyIcons('✅ Berhasil'),
        );
    }

    public function test_message_copy_uses_the_database_icon_and_does_not_rewrite_interpolated_customer_text(): void
    {
        $icon = TelegramUiIcon::query()->where('key', 'message.payment_card')->sole();
        $icon->update([
            'fallback_emoji' => '🪙',
            'custom_emoji_id' => self::EMOJI_ID,
        ]);

        $text = app(TelegramTranslationCatalog::class)->translate(
            'payment.pay_via',
            'en',
            ['method' => 'Customer 💳 choice'],
        );

        $this->assertSame(
            '<tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🪙</tg-emoji> Pay via: Customer 💳 choice',
            $text,
        );
    }

    public function test_rendering_copy_twice_keeps_one_wrapper_and_stays_safe_to_escape(): void
    {
        TelegramUiIcon::query()->where('key', 'message.receipt')->sole()->update([
            'fallback_emoji' => '🧾',
            'custom_emoji_id' => self::EMOJI_ID,
        ]);
        $icons = app(TelegramUiIcons::class);
        $icons->forget();

        $once = $icons->renderLegacyIcons('🧾 Invoice: INV-1');
        $twice = $icons->renderLegacyIcons($once);

        $this->assertSame($once, $twice, 'A second render pass must not nest wrappers.');
        $this->assertSame(1, substr_count($twice, '<tg-emoji'), 'Only one wrapper survives two render passes.');
        $this->assertSame(
            '&lt;tag&gt; <tg-emoji emoji-id="'.self::EMOJI_ID.'">🧾</tg-emoji> Invoice: INV-1',
            app(TelegramHtmlCaption::class)->html('<tag> '.$twice, PHP_INT_MAX),
        );
    }

    public function test_admin_can_save_a_plain_unicode_fallback_for_message_copy(): void
    {
        $this->fakeTelegramHttp();
        $customer = $this->adminCustomer(999);
        $icon = TelegramUiIcon::query()->where('key', 'message.payment_card')->sole();
        $icon->update(['custom_emoji_id' => self::EMOJI_ID]);

        $this->postTelegramWebhook($this->callbackUpdate(
            50202,
            'pick',
            'messageicon:pick:'.$icon->id,
            ['id' => 999],
            999,
            'private',
            777,
        ))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(50203, '🪙', ['id' => 999], 999))->assertOk();

        $this->assertSame('🪙', $icon->fresh()->fallback_emoji);
        $this->assertNull($icon->fresh()->custom_emoji_id);
    }

    public function test_admin_can_save_a_premium_custom_emoji_for_message_copy(): void
    {
        $this->verifiedEmojiFakes();
        $this->adminCustomer(999);
        $icon = TelegramUiIcon::query()->where('key', 'message.payment_card')->sole();

        $this->postTelegramWebhook($this->callbackUpdate(
            50204,
            'pick',
            'messageicon:pick:'.$icon->id,
            ['id' => 999],
            999,
            'private',
            777,
        ))->assertOk();
        $update = $this->messageUpdate(50205, '🏠', ['id' => 999], 999);
        $update['message']['entities'] = [[
            'type' => 'custom_emoji',
            'custom_emoji_id' => self::EMOJI_ID,
            'offset' => 0,
            'length' => 2,
        ]];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertSame(self::EMOJI_ID, $icon->fresh()->custom_emoji_id);
        $this->assertSame('🏠', $icon->fresh()->fallback_emoji);
    }

    public function test_picking_a_menu_slot_prompts_for_that_button_emoji(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $icon = $this->menuIcon('menu.home');

        $this->postTelegramWebhook($this->callbackUpdate(50205, 'pick', 'menuicon:pick:'.$icon->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Beranda', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('menuicon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_menu_icon_list_previews_a_captured_premium_emoji(): void
    {
        $this->verifiedEmojiFakes();
        $customer = $this->adminCustomer(999);
        $this->captureEmoji($customer, 'menu.products');

        $this->postTelegramWebhook($this->callbackUpdate(50260, 'list', 'menuicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $buttons = $this->assertValidInlineKeyboard((array) $this->sentEditRequest(777)['reply_markup']);

        $preview = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'menuicon:pick:'.$this->menuIcon('menu.products')->id);
        $this->assertNotNull($preview, 'Slot yang sudah diubah tetap tampil di daftar.');
        $this->assertSame('✅ Lihat Produk', $preview['text']);
        $this->assertSame(self::EMOJI_ID, $preview['icon_custom_emoji_id'] ?? null);

        $plain = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'menuicon:pick:'.$this->menuIcon('menu.home')->id);
        $this->assertNotNull($plain);
        $this->assertSame('⬜ 🏠 Beranda', $plain['text'], 'Slot yang belum diubah tetap memakai glyph bawaan.');
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $plain);
    }

    public function test_message_icon_list_previews_a_captured_premium_emoji(): void
    {
        $this->verifiedEmojiFakes();
        $this->adminCustomer(999);
        $icon = $this->menuIcon('message.payment_card');

        $this->postTelegramWebhook($this->callbackUpdate(50270, 'pick', 'messageicon:pick:'.$icon->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50271, '🏠', ['id' => 999], 999);
        $update['message']['entities'] = [['type' => 'custom_emoji', 'custom_emoji_id' => self::EMOJI_ID, 'offset' => 0, 'length' => 2]];
        $this->postTelegramWebhook($update)->assertOk();
        $this->assertSame(self::EMOJI_ID, $icon->fresh()->custom_emoji_id);

        $this->postTelegramWebhook($this->callbackUpdate(50272, 'list', 'messageicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $buttons = $this->assertValidInlineKeyboard((array) $this->sentEditRequest(777)['reply_markup']);
        $preview = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'messageicon:pick:'.$icon->id);
        $this->assertNotNull($preview, 'Slot teks pesan yang sudah diubah tetap tampil di daftar.');
        $this->assertSame('✅ '.$icon->label, $preview['text']);
        $this->assertSame(self::EMOJI_ID, $preview['icon_custom_emoji_id'] ?? null);
    }

    public function test_copy_icons_that_were_hard_coded_are_now_editable_slots(): void
    {
        foreach (['message.receipt', 'message.arrow', 'message.account'] as $key) {
            $this->assertNotNull(TelegramUiIcon::query()->where('key', $key)->first(), $key.' harus terdaftar sebagai slot teks.');
        }

        // Emoji that already own a menu button slot must not be duplicated in
        // the text icon list; each emoji stays editable in exactly one list.
        foreach (['📦', '🛍️', '💰', '💬', '🌐', '📢', '⬅️', '🏠'] as $emoji) {
            $this->assertNull(
                TelegramUiIcon::query()->where('key', 'like', 'message.%')->where('source_emoji', $emoji)->first(),
                $emoji.' sudah punya slot menu, jadi tidak boleh muncul lagi di daftar teks.',
            );
        }

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '🧾']],
            ]),
        ]);
        $this->adminCustomer(999);
        $icon = $this->menuIcon('message.receipt');

        $this->postTelegramWebhook($this->callbackUpdate(50280, 'pick', 'messageicon:pick:'.$icon->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50281, '🧾', ['id' => 999], 999);
        $update['message']['entities'] = [['type' => 'custom_emoji', 'custom_emoji_id' => self::EMOJI_ID, 'offset' => 0, 'length' => 2]];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertSame(
            '<tg-emoji emoji-id="'.self::EMOJI_ID.'" data-omp-icon="1">🧾</tg-emoji> Invoice: INV-1',
            app(TelegramUiIcons::class)->renderLegacyIcons('🧾 Invoice: INV-1'),
        );
    }

    public function test_captured_menu_emoji_replaces_the_glyph_on_the_home_keyboard(): void
    {
        $this->verifiedEmojiFakes();
        $customer = $this->adminCustomer(999);
        $this->captureEmoji($customer, 'menu.products');

        $this->postTelegramWebhook($this->messageUpdate(50210, '/menu', ['id' => 999], 999))->assertOk();

        $button = $this->keyboardButton('katalog');
        $this->assertNotNull($button, 'The home keyboard must still expose the product catalog button.');
        $this->assertSame('Lihat Produk', $button['text']);
        $this->assertSame(self::EMOJI_ID, $button['icon_custom_emoji_id'] ?? null);
    }

    public function test_api_menu_slot_uses_existing_admin_capture_and_clear_paths(): void
    {
        $this->verifiedEmojiFakes();
        $customer = $this->adminCustomer(999);
        $this->captureEmoji($customer, 'menu.api');
        $this->postTelegramWebhook($this->messageUpdate(50212, '/start', ['id' => 999], 999))->assertOk();
        $button = $this->keyboardButton('api:menu');
        $this->assertSame(self::EMOJI_ID, $button['icon_custom_emoji_id'] ?? null);
        $this->assertSame('primary', $button['style']);
        $icon = $this->menuIcon('menu.api');
        $this->postTelegramWebhook($this->callbackUpdate(50213, 'clear-api', 'menuicon:clear:'.$icon->id, ['id' => 999], 999, 'private', 777))->assertOk();
        $this->assertNull($icon->fresh()->custom_emoji_id);
        $this->postTelegramWebhook($this->messageUpdate(50214, '/start', ['id' => 999], 999))->assertOk();
        $request = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $markup = $this->telegramRequestField($request, 'reply_markup');
        $markup = is_string($markup) ? json_decode($markup, true, flags: JSON_THROW_ON_ERROR) : $markup;
        $fallback = collect(array_merge(...$markup['inline_keyboard']))->firstWhere('callback_data', 'api:menu');
        $this->assertSame($button['callback_data'], $fallback['callback_data']);
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $fallback);
    }

    public function test_captured_menu_emoji_replaces_the_glyph_on_navigation_rows(): void
    {
        $this->verifiedEmojiFakes();
        $customer = $this->adminCustomer(999);
        $this->captureEmoji($customer, 'menu.back');

        // Routine screens render onto the canonical navigation root, so the menu
        // has to establish one before the orders screen is opened.
        $this->postTelegramWebhook($this->messageUpdate(50220, '/menu', ['id' => 999], 999))->assertOk();
        $rootMessageId = TelegramNavigationRoot::query()->sole()->message_id;
        $this->postTelegramWebhook($this->callbackUpdate(50221, 'orders', 'menu:orders', ['id' => 999], 999, 'private', $rootMessageId))->assertOk();

        $button = $this->keyboardButton('menu:back');
        $this->assertNotNull($button, 'The orders screen must still expose the back button.');
        $this->assertSame('Kembali', $button['text']);
        $this->assertSame(self::EMOJI_ID, $button['icon_custom_emoji_id'] ?? null);
    }

    public function test_custom_emoji_ids_survive_a_database_cache_round_trip(): void
    {
        config()->set('cache.default', 'database');
        config()->set('cache.serializable_classes', false);
        Cache::purge('database');
        Cache::store('database')->flush();

        $icon = TelegramUiIcon::query()->where('key', 'menu.products')->sole();
        $icon->update(['custom_emoji_id' => self::EMOJI_ID]);

        $icons = app(TelegramUiIcons::class);

        $this->assertSame(self::EMOJI_ID, $icons->customEmojiId('menu.products'));
        $this->assertSame(self::EMOJI_ID, $icons->customEmojiId('menu.products'));
    }

    public function test_a_foreign_cached_payload_is_rebuilt_instead_of_breaking_the_read(): void
    {
        Cache::put(TelegramUiIcons::CACHE_KEY, 'stale-payload-from-an-older-release', now()->addMinutes(5));

        $this->assertSame(['text' => '🛍️ Lihat Produk'], app(TelegramUiIcons::class)->apply('menu.products', '🛍️ Lihat Produk'));
        $this->assertIsArray(Cache::get(TelegramUiIcons::CACHE_KEY));
    }

    public function test_the_admin_can_clear_a_menu_icon_again(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $icon = TelegramUiIcon::query()->where('key', 'menu.home')->sole();
        $icon->update(['custom_emoji_id' => self::EMOJI_ID]);

        $this->postTelegramWebhook($this->callbackUpdate(50230, 'clear', 'menuicon:clear:'.$icon->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $this->assertNull($icon->fresh()->custom_emoji_id);
    }

    public function test_a_non_admin_cannot_open_the_menu_icon_list(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 557, 'locale' => 'id']);

        $this->postTelegramWebhook($this->callbackUpdate(50240, 'list', 'menuicon:list', ['id' => 557], 557, 'private', 777))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'Akses ditolak'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    private function adminCustomer(int $telegramId): Customer
    {
        config()->set('services.telegram.admin_user_id', (string) $telegramId);

        return Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'id']);
    }

    private function verifiedEmojiFakes(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '🏠']],
            ]),
        ]);
    }

    private function captureEmoji(Customer $customer, string $key): void
    {
        $icon = TelegramUiIcon::query()->where('key', $key)->sole();

        $this->postTelegramWebhook($this->callbackUpdate(50250, 'pick', 'menuicon:pick:'.$icon->id, ['id' => $customer->telegram_id], (int) $customer->telegram_id, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50251, '🏠', ['id' => (int) $customer->telegram_id], (int) $customer->telegram_id);
        $update['message']['entities'] = [['type' => 'custom_emoji', 'custom_emoji_id' => self::EMOJI_ID, 'offset' => 0, 'length' => 2]];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertSame(self::EMOJI_ID, $icon->fresh()->custom_emoji_id);
    }

    private function menuIcon(string $key): TelegramUiIcon
    {
        return TelegramUiIcon::query()->where('key', $key)->sole();
    }

    /**
     * Telegram answers "Bad Request: expected an Array of InlineKeyboardButton"
     * when a row is a button object instead of a list of buttons, and the HTTP
     * fake cannot catch that, so every icon keyboard is asserted structurally.
     *
     * @param  array<string, mixed>  $markup
     * @return list<array<string, mixed>>
     */
    private function assertValidInlineKeyboard(array $markup): array
    {
        $rows = $markup['inline_keyboard'] ?? null;
        $this->assertIsArray($rows, 'reply_markup harus punya inline_keyboard');
        $this->assertNotSame([], $rows, 'inline_keyboard tidak boleh kosong');

        $buttons = [];
        foreach ($rows as $index => $row) {
            $this->assertIsArray($row, "Baris {$index} harus berupa array.");
            $this->assertTrue(array_is_list($row), "Baris {$index} harus berupa daftar tombol, bukan satu tombol.");
            foreach ($row as $button) {
                $this->assertIsArray($button);
                $this->assertArrayHasKey('text', $button);
                $buttons[] = $button;
            }
        }

        return $buttons;
    }

    private function sentEditRequest(int $messageId): Request
    {
        $requests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) ($request['message_id'] ?? 0) === $messageId);

        $this->assertNotEmpty($requests, 'Tidak ada editMessageText untuk pesan '.$messageId);

        return $requests->last()[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function keyboardButton(string $callbackData): ?array
    {
        foreach (Http::recorded() as [$request]) {
            $markup = $this->telegramRequestField($request, 'reply_markup');
            if (is_string($markup)) {
                $markup = json_decode($markup, true);
            }
            if (! is_array($markup)) {
                continue;
            }

            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && ($button['callback_data'] ?? null) === $callbackData) {
                        return $button;
                    }
                }
            }
        }

        return null;
    }
}
