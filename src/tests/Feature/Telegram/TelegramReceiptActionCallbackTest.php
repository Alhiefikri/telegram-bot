<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Telegram\TelegramIntentSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

final class TelegramReceiptActionCallbackTest extends TelegramBotTestSupport
{
    public function test_buy_again_opens_the_existing_catalog_without_creating_commerce_state(): void
    {
        $this->fakeTelegramHttp();
        [$customer, $order] = $this->paidOrder('en', 425001);
        $session = app(TelegramIntentSession::class);
        $session->start($customer, 1, 1, 'en');
        Cache::put('telegram:catalog-query:'.$customer->telegram_id, [
            'type' => 'catalog',
            'page' => 1,
            'token' => str_repeat('a', 16),
        ]);
        $before = [
            'carts' => $this->countRows('carts'),
            'orders' => $this->countRows('orders'),
            'payments' => $this->countRows('payments'),
            'stock_items' => $this->countRows('product_stock_items'),
            'inventory_movements' => $this->countRows('inventory_movements'),
        ];

        $this->postTelegramWebhook($this->callbackUpdate(
            4251,
            'cb-receipt-buy-again',
            'receipt:buy_again:'.$order->id,
            chatId: 425001,
            messageId: 9411,
        ))->assertOk();

        $this->assertSame($before['carts'], $this->countRows('carts'));
        $this->assertSame($before['orders'], $this->countRows('orders'));
        $this->assertSame($before['payments'], $this->countRows('payments'));
        $this->assertSame($before['stock_items'], $this->countRows('product_stock_items'));
        $this->assertSame($before['inventory_movements'], $this->countRows('inventory_movements'));
        $this->assertNull($session->get($customer));
        $catalogState = Cache::get('telegram:catalog-query:'.$customer->telegram_id);
        $this->assertIsArray($catalogState);
        $this->assertSame('catalog', $catalogState['type'] ?? null);
        $this->assertNotSame(str_repeat('a', 16), $catalogState['token'] ?? null);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'cb-receipt-buy-again'
            && ! isset($request['text']));
    }

    public function test_main_menu_delegates_to_the_existing_locale_aware_menu(): void
    {
        $this->fakeTelegramHttp();
        [$customer, $order] = $this->paidOrder('ms', 425002);
        app(TelegramIntentSession::class)->start($customer, 1, 1, 'ms');

        $this->postTelegramWebhook($this->callbackUpdate(
            4252,
            'cb-receipt-main-menu',
            'receipt:main_menu:'.$order->id,
            chatId: 425002,
        ))->assertOk();

        $message = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $this->assertSame(
            "☰ Menu utama\nApa yang anda mahu lakukan? Pilih butang di bawah.",
            (string) ($message['text'] ?? ''),
        );
        $this->assertSame('keyboard', array_key_first((array) ($message['reply_markup'] ?? [])));
        $this->assertNull(app(TelegramIntentSession::class)->get($customer));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'cb-receipt-main-menu'
            && ! isset($request['text']));
    }

    public function test_wrong_customer_stale_and_malformed_callbacks_fail_without_order_leakage(): void
    {
        $this->fakeTelegramHttp();
        [$owner, $order] = $this->paidOrder('en', 425003);
        $other = Customer::factory()->create(['telegram_id' => 425004, 'locale' => 'en']);
        $order->update(['payment_status' => PaymentStatus::Refunded]);

        $this->postTelegramWebhook($this->callbackUpdate(
            4253,
            'cb-receipt-wrong-owner',
            'receipt:buy_again:'.$order->id,
            from: ['id' => $other->telegram_id],
            chatId: (int) $other->telegram_id,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            4254,
            'cb-receipt-stale',
            'receipt:main_menu:'.$order->id,
            from: ['id' => $owner->telegram_id],
            chatId: (int) $owner->telegram_id,
        ))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(
            4255,
            'cb-receipt-malformed',
            'receipt:buy_again:not-an-order',
            from: ['id' => $owner->telegram_id],
            chatId: (int) $owner->telegram_id,
        ))->assertOk();

        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $answers = Http::recorded(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery'));
        $this->assertCount(3, $answers);
        $this->assertSame(
            'This action is no longer available. Please use the menu or open the catalog again.',
            (string) ($answers[0][0]['text'] ?? ''),
        );
        $this->assertSame(
            'This action is no longer available. Please use the menu or open the catalog again.',
            (string) ($answers[1][0]['text'] ?? ''),
        );
        $this->assertSame('This action is not recognized. Please choose another option.', (string) ($answers[2][0]['text'] ?? ''));
        $this->assertStringNotContainsString((string) $order->id, json_encode($answers->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_non_private_callback_is_denied_without_invoking_navigation(): void
    {
        $this->fakeTelegramHttp();
        [, $order] = $this->paidOrder('en', 425005);

        $this->postTelegramWebhook($this->callbackUpdate(
            4256,
            'cb-receipt-group',
            'receipt:buy_again:'.$order->id,
            chatId: 425005,
            chatType: 'group',
        ))->assertOk();

        $this->assertCount(0, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['text'] ?? null) === 'For security, continue in a private chat with the bot.');
    }

    private function paidOrder(string $locale, int $telegramId): array
    {
        $customer = Customer::factory()->create([
            'telegram_id' => $telegramId,
            'locale' => $locale,
        ]);
        $product = Product::factory()->create(['name' => 'Receipt Action Product']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => 'receipt-action@example.test|Receipt-action-password',
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$customer, $order];
    }

    private function countRows(string $table): int
    {
        return (int) \DB::table($table)->count();
    }
}
