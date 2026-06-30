<?php

namespace Tests\Feature\Telegram;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CartCheckoutTest extends TelegramBotTestSupport
{
    public function test_product_callback_shows_stock_choices_and_quantity_button_adds_cart_item(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(13, 'cb1', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(14, 'cb-qty', 'qty:'.$product->id.':2'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->quantity);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains(json_encode($r->data()), 'qty:'.$product->id.':2'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains(json_encode($r->data()), 'Pembayaran'));
    }

    public function test_manual_non_numeric_quantity_keeps_selected_product_for_retry(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(9001, 'cb-product', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(9002, 'abc'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(9003, '2'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->quantity);
    }

    public function test_invalid_product_callback_and_invalid_quantity_do_not_mutate_cart(): void
    {
        $this->fakeTelegramHttp();
        $inactive = Product::factory()->create(['is_active' => false]);

        $this->postTelegramWebhook($this->callbackUpdate(15, 'cb2', 'product:'.$inactive->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(16, 'cb-qty-bad', 'qty:'.$inactive->id.':1'))->assertOk();

        $this->assertSame(0, CartItem::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery') && ($r['text'] ?? '') === 'Produk tidak tersedia.');
    }

    public function test_katalog_clears_selected_product_and_invalid_callback_answers_once(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['stock' => 5]);

        $this->postTelegramWebhook($this->callbackUpdate(111, 'cb111', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(112, '/katalog'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(113, '2'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(114, 'bad-cb', 'product:0'))->assertOk();

        $this->assertSame(0, CartItem::count());
        $answers = 0;
        Http::assertSent(function ($r) use (&$answers) {
            if (str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'bad-cb') {
                $answers++;
            }

            return true;
        });
        $this->assertSame(1, $answers);
    }

    public function test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin(): void
    {
        config()->set('services.telegram.payment_mode', 'manual_dana');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_contact_url' => 'https://t.me/adminsedulur', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(17, 'cb3', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual', 'method' => 'DANA_QR', 'provider_reference' => null]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '12345'
            && str_contains($r['text'], 'Halo admin, saya ingin konfirmasi pembelian')
            && str_contains($r['text'], 'GPT Plus'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard')
            && str_contains($r['text'], $order->invoice_number));
    }

    public function test_active_manual_dashboard_setting_overrides_pakasir_env_mode(): void
    {
        config()->set('services.telegram.payment_mode', 'pakasir');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Premium Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(171, 'cb-manual-override', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'DANA_QR',
        ]);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '12345'
            && str_contains($r['text'], 'caption /payment')
            && str_contains($r['text'], 'Premium Account'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard'));
    }

    public function test_checkout_callback_in_pakasir_mode_sends_qr_with_direct_payment_button(): void
    {
        config()->set('services.telegram.payment_mode', 'pakasir');
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false]);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'price_amount' => 15000, 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 2);

        $this->postTelegramWebhook($this->callbackUpdate(18, 'cb-pakasir', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'pakasir', 'method' => 'hosted', 'amount' => 30000]);
        $this->assertNotEmpty($payment->merchant_ref);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'https://app.pakasir.test/pay/sedulur-digital/30000?order_id='.rawurlencode($payment->merchant_ref)));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard'));
    }
}
