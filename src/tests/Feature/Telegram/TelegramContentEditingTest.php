<?php

namespace Tests\Feature\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Telegram\TelegramCatalogIntents;
use App\Services\Telegram\TelegramProductContent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

final class TelegramContentEditingTest extends TelegramBotTestSupport
{
    private const FIRE_ID = '5368324170671202286';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.admin_user_id', '12345');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => [['custom_emoji_id' => self::FIRE_ID, 'emoji' => '🔥', 'type' => 'custom_emoji', 'is_animated' => true]]]),
        ]);
    }

    private function begin(Product $product, string $field, int $update = 1, string $locale = 'id'): void
    {
        $this->postTelegramWebhook($this->messageUpdate($update, '/admin'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate($update + 1, 'pick'.$update, 'content:product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate($update + 2, 'edit'.$update, 'content:edit:'.$product->id.':'.$field))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate($update + 3, 'locale'.$update, 'content:locale:'.$product->id.':'.$field.':'.$locale))->assertOk();
    }

    private function replacement(int $update, string $text, array $entities = [], int $reply = 123457): array
    {
        $payload = $this->messageUpdate($update, $text);
        $payload['message']['entities'] = $entities;
        $payload['message']['reply_to_message'] = ['message_id' => $reply];

        return $payload;
    }

    public function test_admin_edits_both_fields_and_customer_detail_preserves_premium_emoji(): void
    {
        $product = Product::factory()->create(['description' => 'Original description', 'warranty_policy' => 'Original policy', 'stock' => 0]);
        $text = '😀 Details 🔥 <script> & original';
        $entity = ['type' => 'custom_emoji', 'offset' => 11, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID];
        $this->begin($product, 'description');
        Http::assertSent(fn (Request $r): bool => ($r['text'] ?? '') === 'Original description');
        $this->postTelegramWebhook($this->replacement(5, $text, [$entity]))->assertOk();
        $this->assertSame($text, $product->fresh()->description);
        $this->assertSame([$entity], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r['text'] ?? ''), 'Telegram tidak mengembalikan emoji premium'));
        $this->begin($product->fresh(), 'warranty_policy', 10);
        Http::assertSent(fn (Request $r): bool => ($r['text'] ?? '') === 'Original policy');
        $this->postTelegramWebhook($this->replacement(14, 'Refund 🔥', [['type' => 'custom_emoji', 'offset' => 7, 'length' => 2, 'custom_emoji_id' => $entity['custom_emoji_id']]]))->assertOk();
        $this->assertSame('Refund 🔥', $product->fresh()->warranty_policy);
        $buyer = Customer::factory()->create(['telegram_id' => '99999']);
        app(TelegramCatalogIntents::class)->sendProductDetail($buyer, '99999', $product->fresh());
        Http::assertSent(function (Request $r) use ($entity): bool {
            $text = $r['text'] ?? '';

            return (string) ($r['chat_id'] ?? '') === '99999'
                && str_contains($text, '<tg-emoji emoji-id="'.$entity['custom_emoji_id'].'">🔥</tg-emoji>')
                && str_contains($text, '&lt;script&gt; &amp; original')
                && str_contains($text, 'Refund <tg-emoji');
        });
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_language_picker_marks_which_variants_are_filled(): void
    {
        $product = Product::factory()->create(['description' => 'Original description', 'description_en' => 'English copy']);
        $this->postTelegramWebhook($this->messageUpdate(1, '/admin'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2, 'pick', 'content:product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(3, 'edit', 'content:edit:'.$product->id.':description'))->assertOk();
        Http::assertSent(function (Request $r) use ($product): bool {
            $markup = json_encode($r['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return str_contains($markup, 'content:locale:'.$product->id.':description:id')
                && str_contains($markup, 'content:locale:'.$product->id.':description:ms')
                && str_contains($markup, 'content:locale:'.$product->id.':description:en')
                && str_contains($markup, 'Bahasa Melayu — kosong')
                && str_contains($markup, 'Bahasa Indonesia — terisi');
        });
    }

    public function test_english_variant_editing_keeps_premium_emoji_inside_that_locale(): void
    {
        $product = Product::factory()->create(['description' => 'Deskripsi Indonesia', 'warranty_policy' => 'Garansi Indonesia', 'stock' => 0]);
        $text = 'English details 🔥 for buyers';
        $entity = ['type' => 'custom_emoji', 'offset' => 16, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID];
        $this->begin($product, 'description', 1, 'en');
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r['text'] ?? ''), 'Product Details (English)'));

        $this->postTelegramWebhook($this->replacement(5, $text, [$entity]))->assertOk();
        $fresh = $product->fresh();
        $this->assertSame($text, $fresh->description_en);
        $this->assertSame('Deskripsi Indonesia', $fresh->description);
        $this->assertSame([$entity], app(TelegramProductContent::class)->entities($fresh, 'description_en'));
        $this->assertSame([], app(TelegramProductContent::class)->entities($fresh, 'description'));

        $english = Customer::factory()->create(['telegram_id' => '88001', 'locale' => 'en']);
        app(TelegramCatalogIntents::class)->sendProductDetail($english, '88001', $product->fresh());
        Http::assertSent(fn (Request $r): bool => (string) ($r['chat_id'] ?? '') === '88001'
            && str_contains((string) ($r['text'] ?? ''), '<tg-emoji emoji-id="'.self::FIRE_ID.'">🔥</tg-emoji> for buyers')
            && ! str_contains((string) ($r['text'] ?? ''), 'Deskripsi Indonesia'));

        $malay = Customer::factory()->create(['telegram_id' => '88002', 'locale' => 'ms']);
        app(TelegramCatalogIntents::class)->sendProductDetail($malay, '88002', $product->fresh());
        Http::assertSent(fn (Request $r): bool => (string) ($r['chat_id'] ?? '') === '88002'
            && str_contains((string) ($r['text'] ?? ''), 'Deskripsi Indonesia')
            && ! str_contains((string) ($r['text'] ?? ''), 'English details'));
    }

    public function test_locale_variants_render_their_own_refund_policy(): void
    {
        $product = Product::factory()->create([
            'description' => 'Deskripsi Indonesia',
            'warranty_policy' => 'Garansi Indonesia',
            'warranty_policy_en' => 'English refund policy',
            'stock' => 0,
        ]);
        $english = Customer::factory()->create(['telegram_id' => '88003', 'locale' => 'en']);
        app(TelegramCatalogIntents::class)->sendProductDetail($english, '88003', $product->fresh());
        Http::assertSent(fn (Request $r): bool => (string) ($r['chat_id'] ?? '') === '88003'
            && str_contains((string) ($r['text'] ?? ''), 'English refund policy')
            && ! str_contains((string) ($r['text'] ?? ''), 'Garansi Indonesia'));

        $base = Customer::factory()->create(['telegram_id' => '88004', 'locale' => 'id']);
        app(TelegramCatalogIntents::class)->sendProductDetail($base, '88004', $product->fresh());
        Http::assertSent(fn (Request $r): bool => (string) ($r['chat_id'] ?? '') === '88004'
            && str_contains((string) ($r['text'] ?? ''), 'Garansi Indonesia')
            && ! str_contains((string) ($r['text'] ?? ''), 'English refund policy'));
    }

    public function test_clearing_a_variant_drops_its_metadata_and_falls_back_to_the_base_copy(): void
    {
        $product = Product::factory()->create([
            'description' => 'Deskripsi Indonesia',
            'description_en' => 'English copy 🔥',
            'stock' => 0,
        ]);
        $product->forceFill(['telegram_content' => ['description_en' => [
            'text' => 'English copy 🔥',
            'entities' => [['type' => 'custom_emoji', 'offset' => 13, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID]],
        ]]])->save();

        $this->begin($product->fresh(), 'description', 1, 'en');
        $this->postTelegramWebhook($this->replacement(5, '/clear'))->assertOk();
        $fresh = $product->fresh();
        $this->assertSame('', $fresh->description_en);
        $this->assertNull($fresh->telegram_content);

        $english = Customer::factory()->create(['telegram_id' => '88005', 'locale' => 'en']);
        app(TelegramCatalogIntents::class)->sendProductDetail($english, '88005', $product->fresh());
        Http::assertSent(fn (Request $r): bool => (string) ($r['chat_id'] ?? '') === '88005'
            && str_contains((string) ($r['text'] ?? ''), 'Deskripsi Indonesia'));
    }

    public function test_variant_copy_over_the_product_detail_budget_is_rejected(): void
    {
        $product = Product::factory()->create([
            'description' => 'Deskripsi Indonesia',
            'warranty_policy_en' => str_repeat('y', 2900),
            'stock' => 0,
        ]);
        $this->begin($product, 'description', 1, 'en');
        $this->postTelegramWebhook($this->replacement(5, str_repeat('x', 200)))->assertOk();
        $this->assertNull($product->fresh()->description_en);
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r['text'] ?? ''), 'Total Product Details + Refund Policy English maksimal 3000'));
    }

    public function test_unauthorized_and_group_edits_do_not_change_content(): void
    {
        $product = Product::factory()->create(['description' => 'Original']);
        $this->postTelegramWebhook($this->callbackUpdate(1, 'foreign', 'content:edit:'.$product->id.':description', chatId: 777))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2, 'group', 'content:edit:'.$product->id.':description', from: ['id' => 12345], chatId: -100, chatType: 'supergroup'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(3, 'Injected', chatId: 777))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $this->assertNull($product->fresh()->telegram_content);
    }

    public function test_wrong_reply_cancel_expiry_and_stale_dashboard_change_are_safe(): void
    {
        $product = Product::factory()->create(['description' => 'Original']);
        $this->begin($product, 'description');
        $this->postTelegramWebhook($this->replacement(5, 'Wrong reply', reply: 44))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $product->update(['description' => 'Dashboard update']);
        $this->postTelegramWebhook($this->replacement(6, 'Stale'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
        $this->postTelegramWebhook($this->messageUpdate(7, '/cancel'))->assertOk();
        $this->postTelegramWebhook($this->replacement(8, 'Cancelled'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
        $this->begin($product->fresh(), 'description', 10);
        $this->travel(11)->minutes();
        $this->postTelegramWebhook($this->replacement(14, 'Expired'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
    }

    public function test_dashboard_plain_text_changes_invalidate_old_emoji_offsets(): void
    {
        $product = Product::factory()->create(['description' => 'Original']);
        $this->begin($product, 'description');
        $this->postTelegramWebhook($this->replacement(5, '🔥', [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => self::FIRE_ID]]))->assertOk();
        $product->refresh()->update(['description' => 'Plain replacement']);
        $this->assertSame([], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
        $this->assertSame('Plain replacement', app(TelegramProductContent::class)->html($product->fresh(), 'description'));
    }

    public function test_invalid_entities_and_oversized_content_are_rejected_then_clear_succeeds(): void
    {
        $product = Product::factory()->create(['description' => 'Original', 'warranty_policy' => 'Policy']);
        $this->begin($product, 'description');
        $this->postTelegramWebhook($this->replacement(5, '🔥', [['type' => 'custom_emoji', 'offset' => 1, 'length' => 1, 'custom_emoji_id' => self::FIRE_ID]]))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $this->postTelegramWebhook($this->replacement(6, str_repeat('x', 3001)))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $this->postTelegramWebhook($this->replacement(7, '/clear'))->assertOk();
        $this->assertSame('', $product->fresh()->description);
        $this->assertSame([], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
    }
}
