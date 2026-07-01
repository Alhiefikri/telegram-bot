<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CartCheckoutTest extends TelegramBotTestSupport
{
    public function test_product_detail_buy_button_shows_quantity_choices_and_quantity_adds_cart_item(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Kopi', 'description' => 'Kopi premium siap kirim.', 'price_amount' => 25000, 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'kopi@example.test | password: Secret-222']);

        $this->postTelegramWebhook($this->callbackUpdate(13, 'cb1', 'product:'.$product->id))->assertOk();
        $this->assertSame(0, CartItem::count());

        $this->postTelegramWebhook($this->callbackUpdate(14, 'cb-buy', 'buy:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(15, 'cb-qty', 'qty:'.$product->id.':2'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $cartItem = CartItem::first();
        $this->assertSame($product->id, $cartItem->product_id);
        $this->assertSame(2, $cartItem->quantity);
        $this->assertSame(CartStatus::Active, $cartItem->cart->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], '📝 Deskripsi')
            && str_contains($r['text'], 'Kopi premium siap kirim.')
            && str_contains($r['text'], 'Harga: Rp25.000')
            && str_contains($r['text'], 'Stok: 5')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'kopi@example.test')
            && str_contains(json_encode($r->data()), 'Beli (Rp25.000)')
            && str_contains(json_encode($r->data()), 'buy:'.$product->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pilih jumlah')
            && str_contains(json_encode($r->data()), 'qty:'.$product->id.':1')
            && str_contains(json_encode($r->data()), 'qty:'.$product->id.':5')
            && ! str_contains(json_encode($r->data()), 'qty:'.$product->id.':6'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], '🛒 Masuk keranjang')
            && str_contains($r['text'], 'Kopi')
            && str_contains($r['text'], 'Jumlah: 2')
            && str_contains($r['text'], 'Subtotal item: Rp50.000')
            && str_contains(json_encode($r->data()), 'Siap checkout? Pilih pembayaran anda.')
            && str_contains(json_encode($r->data()), 'payment:dana')
            && str_contains(json_encode($r->data()), 'payment:gopay')
            && str_contains(json_encode($r->data()), 'payment:shopepay'));
    }

    public function test_buy_quantity_choices_are_capped_and_stock_one_only_offers_one_choice(): void
    {
        $this->fakeTelegramHttp();
        $many = Product::factory()->create(['name' => 'Banyak', 'stock' => 9, 'reserved_stock' => 0, 'is_active' => true]);
        $single = Product::factory()->create(['name' => 'Sisa Satu', 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(130, 'buy-many', 'buy:'.$many->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(131, 'buy-one', 'buy:'.$single->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Banyak')
            && str_contains(json_encode($r->data()), 'qty:'.$many->id.':1')
            && str_contains(json_encode($r->data()), 'qty:'.$many->id.':5')
            && ! str_contains(json_encode($r->data()), 'qty:'.$many->id.':6'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Sisa Satu')
            && str_contains(json_encode($r->data()), 'qty:'.$single->id.':1')
            && ! str_contains(json_encode($r->data()), 'qty:'.$single->id.':2'));
    }

    public function test_manual_non_numeric_quantity_keeps_selected_product_for_retry(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(9001, 'cb-product', 'buy:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(9002, 'abc'))->assertOk();
        $this->assertSame(0, CartItem::count());

        $this->postTelegramWebhook($this->messageUpdate(9003, '2'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(9004, '3'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->quantity);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Jumlah tidak valid. Ketik angka sesuai stok yang tersedia.'));
    }

    public function test_unavailable_product_and_buy_callbacks_do_not_mutate_cart_or_show_payment(): void
    {
        $this->fakeTelegramHttp();
        $inactive = Product::factory()->create(['is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);
        $outOfStock = Product::factory()->create(['is_active' => true, 'stock' => 1, 'reserved_stock' => 1]);

        $this->postTelegramWebhook($this->callbackUpdate(15, 'inactive-product', 'product:'.$inactive->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(16, 'inactive-buy', 'buy:'.$inactive->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(17, 'stock-product', 'product:'.$outOfStock->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(18, 'stock-buy', 'buy:'.$outOfStock->id))->assertOk();

        $this->assertSame(0, CartItem::count());
        $unavailableAnswers = 0;
        Http::assertSent(function ($r) use (&$unavailableAnswers) {
            if (str_ends_with($r->url(), '/answerCallbackQuery') && ($r['text'] ?? '') === 'Produk tidak tersedia.') {
                $unavailableAnswers++;
            }

            return true;
        });
        $this->assertSame(4, $unavailableAnswers);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (str_contains((string) ($r['text'] ?? ''), 'Pilih jumlah')
                || str_contains(json_encode($r->data()), 'payment:dana')
                || str_contains(json_encode($r->data()), 'payment:gopay')
                || str_contains(json_encode($r->data()), 'payment:shopepay')));
    }

    public function test_quantity_callback_tampering_does_not_create_empty_carts(): void
    {
        $this->fakeTelegramHttp();
        $inactive = Product::factory()->create(['name' => 'Inactive', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => false]);
        $outOfStock = Product::factory()->create(['name' => 'Out', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => true]);
        $available = Product::factory()->create(['name' => 'Available', 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);

        $callbacks = [
            'qty:999999:1',
            'qty:'.$available->id.':999',
            'qty:'.$available->id.':0',
            'qty:'.$available->id.':-1',
            'qty:'.$inactive->id.':1',
            'qty:'.$outOfStock->id.':1',
        ];

        foreach ($callbacks as $index => $callback) {
            CartItem::query()->delete();
            Cart::query()->delete();

            $this->postTelegramWebhook($this->callbackUpdate(1900 + $index, 'tamper-'.$index, $callback, [], 7000 + $index))
                ->assertOk();

            $this->assertSame(0, Cart::count(), "Callback {$callback} should not create an empty cart.");
            $this->assertSame(0, CartItem::count(), "Callback {$callback} should not create a cart item.");
        }
    }

    public function test_checkout_callback_without_active_cart_and_duplicate_checkout_do_not_create_empty_carts(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(1950, 'checkout-empty', 'checkout', [], 7100))
            ->assertOk();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());

        $product = Product::factory()->create(['name' => 'Checkout Product', 'price_amount' => 15000, 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'checkout-secret-1']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'checkout-secret-2']);

        $this->postTelegramWebhook($this->callbackUpdate(1951, 'qty-valid', 'qty:'.$product->id.':1', [], 7101))
            ->assertOk();
        $this->assertSame(1, Cart::where('status', CartStatus::Active)->count());

        $this->postTelegramWebhook($this->callbackUpdate(1952, 'checkout-first', 'checkout', [], 7101))
            ->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1953, 'checkout-duplicate', 'checkout', [], 7101))
            ->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame(0, Cart::where('status', CartStatus::Active)->count());
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

    public function test_batal_abandons_active_cart_clears_selected_product_and_is_repeatable(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'reserved_stock' => 0]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(190, 'buy-before-cancel', 'buy:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(191, '/batal'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(192, '/batal'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(193, '2'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(CartStatus::Abandoned, $customer->carts()->firstOrFail()->status);
        $this->assertSame(0, $customer->carts()->where('status', CartStatus::Active)->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Keranjang aktif dibatalkan.'));
    }

    public function test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin(): void
    {
        config()->set('services.telegram.payment_mode', 'manual_dana');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_contact_url' => 'https://t.me/adminsedulur', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(17, 'cb3', 'payment:dana'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual', 'method' => 'DANA', 'provider_reference' => null]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '12345'
            && str_contains($r['text'], '✅ Checkout dibuat')
            && str_contains($r['text'], 'Bayar via: DANA')
            && str_contains($r['text'], '082190715067')
            && str_contains($r['text'], 'A/N MOH. ALIF FIKRI')
            && str_contains($r['text'], '/payment')
            && str_contains($r['text'], 'Bantuan: @Alhie02')
            && str_contains($r['text'], 'GPT Plus')
            && ! str_contains($r['text'], 'Halo admin'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout manual baru')
            && str_contains($r['text'], 'Metode: DANA')
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

        $this->postTelegramWebhook($this->callbackUpdate(171, 'cb-manual-override', 'payment:gopay'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'GOPAY',
        ]);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '12345'
            && str_contains($r['text'], 'Bayar via: GOPAY')
            && str_contains($r['text'], 'caption:')
            && str_contains($r['text'], 'Premium Account'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout manual baru'));
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
