<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class ProductIconCaptureTest extends TelegramBotTestSupport
{
    private const EMOJI_ID = '5368324170671202286';

    public function test_admin_home_exposes_the_product_icon_entry(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);

        $this->postTelegramWebhook($this->messageUpdate(50100, '/start', ['id' => 999], 999))->assertOk();

        $button = $this->keyboardButton('producticon:list');

        $this->assertNotNull($button, 'The admin home keyboard must expose the product icon entry.');
        $this->assertSame('🎨 Ikon Produk', $button['text']);
    }

    public function test_the_list_shows_the_products_that_exist_in_the_database(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $ready = $this->product('QA Gmail Fresh', '📩', self::EMOJI_ID);
        $this->product('QA ChatGPT Plus 1 Bulan', '🤖', null);

        $this->postTelegramWebhook($this->callbackUpdate(50105, 'list', 'producticon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon Produk (2)', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $labels = array_column($buttons, 'text');
        $this->assertContains('✅ QA Gmail Fresh', $labels);
        $this->assertContains('⬜ 🤖 QA ChatGPT Plus 1 Bulan', $labels);
        $this->assertContains('🧭 Ikon Menu', $labels);

        $readyButton = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'producticon:pick:'.$ready->id);
        $this->assertNotNull($readyButton, 'Produk yang sudah punya emoji premium harus tampil di daftar.');
        $this->assertSame(self::EMOJI_ID, $readyButton['icon_custom_emoji_id'] ?? null);
    }

    public function test_the_list_groups_variants_under_their_family(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $family = ProductFamily::factory()->create(['name' => 'QA Family Line', 'slug' => 'qa-family-line', 'icon' => '🎬']);
        $this->product('QA Family Plan A', '🎬', null, $family);
        $this->product('QA Family Plan B', '🎬', null, $family);
        $this->product('QA Standalone', '📩', null);

        $this->postTelegramWebhook($this->callbackUpdate(50150, 'list', 'producticon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon Produk (2)', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $labels = array_column($buttons, 'text');
        $callbacks = array_column($buttons, 'callback_data');
        $this->assertContains('⬜ 🎬 QA Family Line', $labels, 'Family tampil sekali dengan ikon milik family, bukan per varian.');
        $this->assertContains('⬜ 📩 QA Standalone', $labels, 'Produk tanpa family tetap bisa diatur sendiri.');
        $this->assertNotContains('⬜ 🎬 QA Family Plan A', $labels);
        $this->assertNotContains('⬜ 🎬 QA Family Plan B', $labels);
        $this->assertContains('familyicon:pick:'.$family->id, $callbacks);
    }

    public function test_a_family_capture_sets_the_family_icon_without_overwriting_variants(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '🎬']],
            ]),
        ]);
        $this->adminCustomer(999);
        $family = ProductFamily::factory()->create(['name' => 'QA Family Line', 'slug' => 'qa-family-line']);
        $first = $this->product('QA Family Plan A', null, null, $family);
        $second = $this->product('QA Family Plan B', '🎯', null, $family);

        $this->postTelegramWebhook($this->callbackUpdate(50155, 'pick', 'familyicon:pick:'.$family->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Family Line', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('producticon:family:'.$family->id, $buttons[0]['callback_data'] ?? null, 'Family screen offers the per-product icon list.');

        $update = $this->messageUpdate(50156, '🎬', ['id' => 999], 999);
        $update['message']['entities'] = [[
            'type' => 'custom_emoji',
            'custom_emoji_id' => self::EMOJI_ID,
            'offset' => 0,
            'length' => 2,
        ]];
        $this->postTelegramWebhook($update)->assertOk();

        $family = $family->fresh();
        $this->assertSame(self::EMOJI_ID, $family->custom_emoji_id, 'Ikon family disimpan pada family.');
        $this->assertSame('🎬', $family->icon);
        $this->assertNull($first->fresh()->custom_emoji_id, 'Varian tanpa ikon sendiri tetap mengikuti family.');
        $this->assertNull($first->fresh()->icon);
        $this->assertSame('🎯', $second->fresh()->icon, 'Ikon khusus varian tidak ditimpa ikon family.');
    }

    public function test_a_variant_list_offers_every_variant_with_its_effective_icon(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $family = ProductFamily::factory()->create([
            'name' => 'QA Family Line',
            'slug' => 'qa-family-line',
            'icon' => '🎬',
            'custom_emoji_id' => self::EMOJI_ID,
        ]);
        $this->product('QA Family Plan A', null, null, $family);
        $this->product('QA Family Plan B', '🎯', null, $family);

        $this->postTelegramWebhook($this->callbackUpdate(50160, 'variants', 'producticon:family:'.$family->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Ikon per Produk', (string) $request['text']);
        $this->assertStringContainsString('QA Family Line', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $inherited = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'producticon:pick:'.$family->products()->where('name', 'QA Family Plan A')->value('id'));
        $override = collect($buttons)->first(fn (array $button): bool => ($button['callback_data'] ?? null) === 'producticon:pick:'.$family->products()->where('name', 'QA Family Plan B')->value('id'));

        $this->assertNotNull($inherited);
        $this->assertSame('⬜ QA Family Plan A', $inherited['text'], 'Varian tanpa ikon sendiri ditandai belum diatur.');
        $this->assertSame(self::EMOJI_ID, $inherited['icon_custom_emoji_id'] ?? null, 'Pratinjau memakai ikon family yang diwarisi.');
        $this->assertNotNull($override);
        $this->assertSame('✅ 🎯 QA Family Plan B', $override['text'], 'Varian dengan ikon sendiri ditandai sudah diatur.');
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $override);
        $this->assertSame('producticon:list', $buttons[count($buttons) - 1]['callback_data'] ?? null);
    }

    public function test_a_variant_capture_stores_an_own_icon_and_clear_releases_it(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '🤖']],
            ]),
        ]);
        $this->adminCustomer(999);
        $family = ProductFamily::factory()->create([
            'name' => 'QA Family Line',
            'slug' => 'qa-family-line',
            'icon' => '🎬',
            'custom_emoji_id' => self::EMOJI_ID,
        ]);
        $variant = $this->product('QA Family Plan A', null, null, $family);

        $this->postTelegramWebhook($this->callbackUpdate(50170, 'pick', 'producticon:pick:'.$variant->id, ['id' => 999], 999, 'private', 777))->assertOk();
        $prompt = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Family Plan A', (string) $prompt['text']);
        $promptButtons = $this->assertValidInlineKeyboard((array) $prompt['reply_markup']);
        $this->assertSame('producticon:family:'.$family->id, $promptButtons[0]['callback_data'] ?? null, 'Varian kembali ke daftar varian family-nya.');

        $update = $this->messageUpdate(50171, '🤖', ['id' => 999], 999);
        $update['message']['entities'] = [[
            'type' => 'custom_emoji',
            'custom_emoji_id' => self::EMOJI_ID,
            'offset' => 0,
            'length' => 2,
        ]];
        $this->postTelegramWebhook($update)->assertOk();

        $variant = $variant->fresh();
        $this->assertSame(self::EMOJI_ID, $variant->custom_emoji_id);
        $this->assertSame('🤖', $variant->icon);
        $this->assertSame('🎬', $family->fresh()->icon, 'Ikon family tidak ikut berubah.');

        $confirmRequest = $this->sentEditRequest(777);
        $confirmButtons = $this->assertValidInlineKeyboard((array) $confirmRequest['reply_markup']);
        $this->assertSame('🗑️ Kembali ke ikon family', $confirmButtons[0]['text'] ?? null, 'Varian family ditawari kembali ke ikon family, bukan sekadar hapus emoji.');

        $this->postTelegramWebhook($this->callbackUpdate(50172, 'clear', 'producticon:clear:'.$variant->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $variant = $variant->fresh();
        $this->assertNull($variant->custom_emoji_id, 'Hapus membebaskan varian dari ikon khusus.');
        $this->assertNull($variant->icon, 'Glyph khusus ikut dibersihkan agar varian mewarisi ikon family.');
        $clearRequest = $this->sentEditRequest(777);
        $clearButtons = $this->assertValidInlineKeyboard((array) $clearRequest['reply_markup']);
        $this->assertSame('producticon:family:'.$family->id, $clearButtons[0]['callback_data'] ?? null);
    }

    public function test_the_list_reports_an_empty_catalog_instead_of_stale_brands(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);

        $this->postTelegramWebhook($this->callbackUpdate(50106, 'list', 'producticon:list', ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('Belum ada produk', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $callbacks = array_column($buttons, 'callback_data');
        $this->assertNotContains('producticon:pick:1', $callbacks);
        $this->assertContains('menuicon:list', $callbacks);
    }

    public function test_customer_home_never_exposes_the_product_icon_entry(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 555, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(50101, '/start', ['id' => 555], 555))->assertOk();

        $this->assertNull($this->keyboardButton('producticon:list'));
    }

    public function test_picking_a_product_prompts_for_that_products_emoji(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', '📩', null);

        $this->postTelegramWebhook($this->callbackUpdate(50110, 'pick', 'producticon:pick:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Gmail Fresh', (string) $request['text']);
        $this->assertStringContainsString('📩 QA Gmail Fresh', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('producticon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_a_captured_custom_emoji_sets_its_matching_text_glyph(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response([
                'ok' => true,
                'result' => [['custom_emoji_id' => self::EMOJI_ID, 'emoji' => '📩']],
            ]),
        ]);
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', '🧠', null);

        $this->postTelegramWebhook($this->callbackUpdate(50120, 'pick', 'producticon:pick:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50121, '📩', ['id' => 999], 999);
        $update['message']['entities'] = [[
            'type' => 'custom_emoji',
            'custom_emoji_id' => self::EMOJI_ID,
            'offset' => 0,
            'length' => 2,
        ]];
        $this->postTelegramWebhook($update)->assertOk();

        $fresh = $product->fresh();
        $this->assertSame(self::EMOJI_ID, $fresh->custom_emoji_id);
        $this->assertSame('📩', $fresh->icon, 'Unicode fallback should match Telegram’s custom emoji.');

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Gmail Fresh', (string) $request['text']);
        $this->assertStringContainsString('📩 QA Gmail Fresh', (string) $request['text']);
        $this->assertTrue(collect($request->data()['entities'] ?? [])->contains(
            fn (array $entity): bool => $entity['type'] === 'custom_emoji'
                && $entity['custom_emoji_id'] === self::EMOJI_ID,
        ));
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('producticon:clear:'.$product->id, $buttons[0]['callback_data'] ?? null);
        $this->assertSame('🗑️ Hapus emoji', $buttons[0]['text'] ?? null, 'Produk tanpa family tetap menawarkan hapus emoji.');
        $this->assertSame('producticon:list', $buttons[1]['callback_data'] ?? null);
    }

    public function test_a_plain_message_while_pending_asks_for_the_emoji_again(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', '📩', null);

        $this->postTelegramWebhook($this->callbackUpdate(50130, 'pick', 'producticon:pick:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(50131, 'halo', ['id' => 999], 999))->assertOk();

        $fresh = $product->fresh();
        $this->assertNull($fresh->custom_emoji_id);
        $this->assertSame('📩', $fresh->icon, 'Pesan biasa tidak boleh menimpa ikon teks.');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'bukan emoji'));
    }

    public function test_a_plain_emoji_sets_the_catalog_text_glyph(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', null, null);

        $this->postTelegramWebhook($this->callbackUpdate(50170, 'pick', 'producticon:pick:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(50171, '📮', ['id' => 999], 999))->assertOk();

        $fresh = $product->fresh();
        $this->assertSame('📮', $fresh->icon, 'Emoji biasa dari bot harus jadi ikon teks.');
        $this->assertNull($fresh->custom_emoji_id);

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Gmail Fresh', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('producticon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_an_unknown_custom_emoji_identifier_is_rejected(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', '📩', null);

        $this->postTelegramWebhook($this->callbackUpdate(50140, 'pick', 'producticon:pick:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $update = $this->messageUpdate(50141, '📩', ['id' => 999], 999);
        $update['message']['entities'] = [['type' => 'custom_emoji', 'custom_emoji_id' => '9999999999']];
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertNull($product->fresh()->custom_emoji_id);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'tidak mengenali emoji'));
    }

    public function test_the_admin_can_remove_a_captured_emoji_again(): void
    {
        $this->fakeTelegramHttp();
        $this->adminCustomer(999);
        $product = $this->product('QA Gmail Fresh', '📩', self::EMOJI_ID);

        $this->postTelegramWebhook($this->callbackUpdate(50150, 'clear', 'producticon:clear:'.$product->id, ['id' => 999], 999, 'private', 777))->assertOk();

        $this->assertNull($product->fresh()->custom_emoji_id);

        $request = $this->sentEditRequest(777);
        $this->assertStringContainsString('QA Gmail Fresh', (string) $request['text']);
        $buttons = $this->assertValidInlineKeyboard((array) $request['reply_markup']);
        $this->assertSame('producticon:list', $buttons[0]['callback_data'] ?? null);
    }

    public function test_a_non_admin_cannot_open_the_product_list(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 556, 'locale' => 'id']);

        $this->postTelegramWebhook($this->callbackUpdate(50160, 'list', 'producticon:list', ['id' => 556], 556, 'private', 777))->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) ($request['text'] ?? ''), 'Akses ditolak'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    private function adminCustomer(int $telegramId): Customer
    {
        config()->set('services.telegram.admin_user_id', (string) $telegramId);

        return Customer::factory()->create(['telegram_id' => $telegramId, 'locale' => 'id']);
    }

    private function product(string $name, ?string $icon, ?string $customEmojiId, ?ProductFamily $family = null): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'icon' => $icon,
            'custom_emoji_id' => $customEmojiId,
            'product_family_id' => $family?->id,
            'family_sort_order' => 1,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
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
