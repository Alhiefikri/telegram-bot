<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramUiIcon;
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
        $this->assertContains('brandicon:list', $callbacks);
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
