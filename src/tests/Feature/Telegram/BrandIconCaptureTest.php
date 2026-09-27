<?php

namespace Tests\Feature\Telegram;

use App\Models\CatalogBrandIcon;
use App\Models\Customer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class BrandIconCaptureTest extends TelegramBotTestSupport
{
    private const EMOJI_ID = '5368324170671202286';

    public function test_admin_home_exposes_the_brand_icon_entry(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);

        $this->postTelegramWebhook($this->messageUpdate(50100, '/start', ['id' => 999], 999))->assertOk();

        $button = $this->keyboardButton('brandicon:list');

        $this->assertNotNull($button, 'The admin home keyboard must expose the brand icon entry.');
        $this->assertSame('🎨 Ikon Brand', $button['text']);
    }

    public function test_admin_can_open_the_brand_list(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        CatalogBrandIcon::query()->where('slug', 'netflix')->sole()->update(['custom_emoji_id' => self::EMOJI_ID]);

        $this->postTelegramWebhook($this->callbackUpdate(50105, 'list', 'brandicon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon Brand', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $labels = array_column($buttons, 'text');
        $this->assertContains('✅ Netflix', $labels);
        $this->assertContains('⬜ ChatGPT', $labels);
        $this->assertContains('🧭 Ikon Menu', $labels);
    }

    public function test_customer_home_never_exposes_the_brand_icon_entry(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 555, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(50101, '/start', ['id' => 555], 555))->assertOk();

        $this->assertNull($this->keyboardButton('brandicon:list'));
    }

    public function test_picking_a_brand_prompts_for_that_brands_emoji(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $brand = $this->brand('netflix');

        $this->postTelegramWebhook($this->callbackUpdate(50110, 'pick', 'brandicon:pick:'.$brand->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Netflix', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('brandicon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_the_emoji_the_admin_sends_is_captured_on_the_brand(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '📺']],
            ]),
        ]);
        $this->adminCustomer(999);
        $brand = $this->brand('netflix');

        $this->postTelegramWebhook($this->callbackUpdate(50120, 'pick', 'brandicon:pick:'.$brand->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50121, '📺', ['id' => 999], 999);
        $update['message']['entities'] = [[
            'type' => 'custom_emoji',
            'custom_emoji_id' => self::EMOJI_ID,
            'offset' => 0,
            'length' => 2,
        ]];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertSame(self::EMOJI_ID, $brand->fresh()->custom_emoji_id);

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Netflix', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('brandicon:clear:'.$brand->id, $buttons[0]['callback_data'] ?? null);
        $this->assertSame('brandicon:list', $buttons[1]['callback_data'] ?? null);
    }

    public function test_a_plain_message_while_pending_asks_for_the_emoji_again(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $brand = $this->brand('netflix');

        $this->postTelegramWebhook($this->callbackUpdate(50130, 'pick', 'brandicon:pick:'.$brand->id, ['id' => 999], 999, 'private', 777))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(50131, 'halo', ['id' => 999], 999))->assertOk();

        $this->assertNull($brand->fresh()->custom_emoji_id);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'bukan emoji custom'));
    }

    public function test_an_unknown_custom_emoji_identifier_is_rejected(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->adminCustomer(999);
        $brand = $this->brand('netflix');

        $this->postTelegramWebhook($this->callbackUpdate(50140, 'pick', 'brandicon:pick:'.$brand->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50141, '📺', ['id' => 999], 999);
        $update['message']['entities'] = [['type' => 'custom_emoji', 'custom_emoji_id' => '9999999999']];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertNull($brand->fresh()->custom_emoji_id);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'tidak mengenali emoji'));
    }

    public function test_the_admin_can_remove_a_captured_emoji_again(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $brand = $this->brand('netflix');
        $brand->update(['custom_emoji_id' => self::EMOJI_ID]);

        $this->postTelegramWebhook($this->callbackUpdate(50150, 'clear', 'brandicon:clear:'.$brand->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $this->assertNull($brand->fresh()->custom_emoji_id);

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Netflix', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('brandicon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_a_non_admin_cannot_open_the_brand_list(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 556, 'locale' => 'id']);

        $this->postTelegramWebhook($this->callbackUpdate(50160, 'list', 'brandicon:list', ['id' => 556], 556, 'private', 777))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'Akses ditolak'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    private function adminCustomer(int $telegramId): Customer
    {
        config()->set('services.telegram.admin_user_id', (string) $telegramId);

        return Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'id']);
    }

    private function brand(string $slug): CatalogBrandIcon
    {
        return CatalogBrandIcon::query()->where('slug', $slug)->sole();
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
