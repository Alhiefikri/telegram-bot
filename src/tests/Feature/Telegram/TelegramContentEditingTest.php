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
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.admin_user_id', '12345');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/getCustomEmojiStickers' => Http::response(['ok' => true, 'result' => [['custom_emoji_id' => '5368324170671202286', 'emoji' => '🔥', 'type' => 'custom_emoji', 'is_animated' => true]]]),
        ]);
    }

    private function begin(Product $product, string $field, int $update = 1): void
    {
        $this->postTelegramWebhook($this->messageUpdate($update, '/admin'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate($update + 1, 'pick'.$update, 'content:product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate($update + 2, 'edit'.$update, 'content:edit:'.$product->id.':'.$field))->assertOk();
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
        $entity = ['type' => 'custom_emoji', 'offset' => 11, 'length' => 2, 'custom_emoji_id' => '5368324170671202286'];
        $this->begin($product, 'description');
        Http::assertSent(fn (Request $r): bool => ($r['text'] ?? '') === 'Original description');
        $this->postTelegramWebhook($this->replacement(4, $text, [$entity]))->assertOk();
        $this->assertSame($text, $product->fresh()->description);
        $this->assertSame([$entity], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
        Http::assertSent(fn (Request $r): bool => str_contains((string) ($r['text'] ?? ''), 'Telegram tidak mengembalikan emoji premium'));
        $this->begin($product->fresh(), 'warranty_policy', 10);
        Http::assertSent(fn (Request $r): bool => ($r['text'] ?? '') === 'Original policy');
        $this->postTelegramWebhook($this->replacement(13, 'Refund 🔥', [['type' => 'custom_emoji', 'offset' => 7, 'length' => 2, 'custom_emoji_id' => $entity['custom_emoji_id']]]))->assertOk();
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
        $this->postTelegramWebhook($this->replacement(4, 'Wrong reply', reply: 44))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $product->update(['description' => 'Dashboard update']);
        $this->postTelegramWebhook($this->replacement(5, 'Stale'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
        $this->postTelegramWebhook($this->messageUpdate(6, '/cancel'))->assertOk();
        $this->postTelegramWebhook($this->replacement(7, 'Cancelled'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
        $this->begin($product->fresh(), 'description', 10);
        $this->travel(11)->minutes();
        $this->postTelegramWebhook($this->replacement(13, 'Expired'))->assertOk();
        $this->assertSame('Dashboard update', $product->fresh()->description);
    }

    public function test_dashboard_plain_text_changes_invalidate_old_emoji_offsets(): void
    {
        $product = Product::factory()->create(['description' => 'Original']);
        $this->begin($product, 'description');
        $this->postTelegramWebhook($this->replacement(4, '🔥', [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '5368324170671202286']]))->assertOk();
        $product->refresh()->update(['description' => 'Plain replacement']);
        $this->assertSame([], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
        $this->assertSame('Plain replacement', app(TelegramProductContent::class)->html($product->fresh(), 'description'));
    }

    public function test_invalid_entities_and_oversized_content_are_rejected_then_clear_succeeds(): void
    {
        $product = Product::factory()->create(['description' => 'Original', 'warranty_policy' => 'Policy']);
        $this->begin($product, 'description');
        $this->postTelegramWebhook($this->replacement(4, '🔥', [['type' => 'custom_emoji', 'offset' => 1, 'length' => 1, 'custom_emoji_id' => '5368324170671202286']]))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $this->postTelegramWebhook($this->replacement(5, str_repeat('x', 3001)))->assertOk();
        $this->assertSame('Original', $product->fresh()->description);
        $this->postTelegramWebhook($this->replacement(6, '/clear'))->assertOk();
        $this->assertSame('', $product->fresh()->description);
        $this->assertSame([], app(TelegramProductContent::class)->entities($product->fresh(), 'description'));
    }
}
