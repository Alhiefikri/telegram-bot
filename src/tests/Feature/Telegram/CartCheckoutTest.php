<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramBotTestSupport;

class CartCheckoutTest extends TelegramBotTestSupport
{
    public function test_product_detail_buy_button_shows_quantity_choices_and_quantity_adds_cart_item(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Kopi', 'description' => 'Kopi premium siap kirim.', 'price_amount' => 25000, 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);
        foreach (range(1, 5) as $index) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => "kopi-{$index}@example.test | password: Secret-222"]);
        }

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
            && str_contains($r['text'], '📝 Detail produk')
            && str_contains($r['text'], 'Kopi premium siap kirim.')
            && str_contains($r['text'], 'Harga: Rp25.000')
            && str_contains($r['text'], 'Stok: 5')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'kopi@example.test')
            && str_contains(json_encode($r->data()), 'Beli Sekarang - Rp25.000')
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
            && str_contains($r['text'], 'Subtotal: Rp50.000')
            && str_contains(json_encode($r->data()), 'Sudah cocok? Lanjutkan checkout di bawah.')
            && str_contains(json_encode($r->data()), 'checkout')
            && str_contains(json_encode($r->data()), 'Lanjut Checkout')
            && ! str_contains(json_encode($r->data()), 'payment:'));
    }

    public function test_reselecting_same_quantity_after_product_navigation_resends_checkout_prompt(): void
    {
        $this->fakeTelegramHttp();
        $productA = Product::factory()->create(['name' => 'Kopi A', 'price_amount' => 25000, 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);
        $productB = Product::factory()->create(['name' => 'Kopi B', 'price_amount' => 30000, 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(2001, 'a-detail-first', 'product:'.$productA->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2002, 'a-buy-first', 'buy:'.$productA->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2003, 'a-qty-first', 'qty:'.$productA->id.':1'))->assertOk();

        $this->postTelegramWebhook($this->callbackUpdate(2004, 'b-detail', 'product:'.$productB->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2005, 'uncategorized', 'category:uncategorized'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2006, 'back-categories', 'back:categories'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2007, 'a-detail-second', 'product:'.$productA->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2008, 'a-buy-second', 'buy:'.$productA->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2009, 'a-qty-second', 'qty:'.$productA->id.':1'))->assertOk();

        $this->assertSame(1, CartItem::count());
        $cartItem = CartItem::first();
        $this->assertSame($productA->id, $cartItem->product_id);
        $this->assertSame(1, $cartItem->quantity);
        $this->assertSame(CartStatus::Active, $cartItem->cart->status);
        $this->assertCount(2, $this->cartConfirmationMessagesFor('Kopi A', 1));
    }

    public function test_stale_quantity_buttons_keep_their_product_id_after_switching_products(): void
    {
        $this->fakeTelegramHttp();
        $productA = Product::factory()->create(['name' => 'Stale A', 'price_amount' => 12000, 'stock' => 4, 'reserved_stock' => 0, 'is_active' => true]);
        $productB = Product::factory()->create(['name' => 'Fresh B', 'price_amount' => 18000, 'stock' => 4, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(2010, 'a-buy', 'buy:'.$productA->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2011, 'b-detail', 'product:'.$productB->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2012, 'b-buy', 'buy:'.$productB->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2013, 'stale-a-qty', 'qty:'.$productA->id.':1'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(2014, 'fresh-b-qty', 'qty:'.$productB->id.':1'))->assertOk();

        $this->assertSame(2, CartItem::count());
        $this->assertDatabaseHas('cart_items', ['product_id' => $productA->id, 'quantity' => 1]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $productB->id, 'quantity' => 1]);
        $this->assertCount(1, $this->cartConfirmationMessagesFor('Stale A', 1));
        $this->assertCount(1, $this->cartConfirmationMessagesFor('Fresh B', 1));
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
            && str_contains($r['text'], 'Angkanya belum pas nih. Pilih jumlah sesuai stok yang tersedia ya.'));
    }

    public function test_unavailable_product_and_buy_callbacks_do_not_mutate_cart_or_show_payment(): void
    {
        $this->fakeTelegramHttp();
        $inactive = Product::factory()->create(['is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);
        $available = Product::factory()->create(['name' => 'Ready', 'stock' => 5, 'reserved_stock' => 0, 'is_active' => true]);
        $outOfStock = Product::factory()->create(['name' => 'Kosong', 'description' => 'Masih bisa dibaca.', 'is_active' => true, 'stock' => 1, 'reserved_stock' => 1]);

        $this->postTelegramWebhook($this->callbackUpdate(14, 'ready-buy', 'buy:'.$available->id))->assertOk();
        $this->fakeTelegramHttp();
        $this->postTelegramWebhook($this->callbackUpdate(15, 'inactive-product', 'product:'.$inactive->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(16, 'inactive-buy', 'buy:'.$inactive->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(17, 'stock-product', 'product:'.$outOfStock->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(18, 'stock-buy', 'buy:'.$outOfStock->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(19, '1'))->assertOk();

        $this->assertSame(0, CartItem::count());
        $unavailableAnswers = 0;
        Http::assertSent(function ($r) use (&$unavailableAnswers) {
            if (str_ends_with($r->url(), '/answerCallbackQuery') && ($r['text'] ?? '') === 'Produk ini belum bisa dibeli sekarang.') {
                $unavailableAnswers++;
            }

            return true;
        });
        $this->assertSame(3, $unavailableAnswers);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Kosong')
            && str_contains($r['text'], '❌ Status: Stok kosong')
            && str_contains($r['text'], 'Masih bisa dibaca.')
            && ! str_contains(json_encode($r->data()), 'buy:'.$outOfStock->id)
            && ! str_contains(json_encode($r->data()), 'Beli'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (str_contains((string) ($r['text'] ?? ''), 'Pilih jumlah')
                || str_contains(json_encode($r->data()), 'payment:')));
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

    public function test_checkout_revalidates_changed_stock_and_prompts_cart_update(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['name' => 'Berubah', 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $product->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();

        $this->postTelegramWebhook($this->callbackUpdate(1954, 'checkout-changed-stock', 'checkout'))->assertOk();

        $this->assertSame(0, Order::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'stok berubah')
            && str_contains($r['text'], 'pilih produk ulang'));
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
            && str_contains($r['text'], 'Keranjang aktif sudah dibatalkan.'));
    }

    public function test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_contact_url' => 'https://t.me/adminsedulur', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(17, 'cb3', 'checkout', ['username' => 'buyer']))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual', 'method' => 'QR_MANUAL', 'provider_reference' => null]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '12345')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat')
            && str_contains($r->body(), 'Bayar via: QR Manual')
            && str_contains($r->body(), 'Nama QR: LHIE STORE')
            && str_contains($r->body(), 'Nominal wajib sama persis')
            && str_contains($r->body(), '/payment')
            && str_contains($r->body(), 'GPT Plus')
            && ! str_contains($r->body(), 'Halo admin'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout belum bayar')
            && str_contains($r['text'], '@buyer')
            && str_contains($r['text'], 'Sumber: Telegram Bot')
            && str_contains($r['text'], 'Metode: QR Manual')
            && str_contains($r['text'], $order->invoice_number)
            && ! str_contains($r['text'], 'Escrow'));
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertNull($order->fresh()->manual_checkout_admin_notified_at);
        $this->assertNotNull($order->fresh()->admin_pending_order_notified_at);
    }

    public function test_manual_checkout_enqueues_outbound_notifications_without_inline_photo_or_admin_send(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        Queue::fake([SendManualCheckoutNotifications::class, SendAdminOrderPendingNotification::class]);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(173, 'cb-manual-queued', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        Queue::assertPushedOn('telegram-outbound', SendManualCheckoutNotifications::class, fn (SendManualCheckoutNotifications $job): bool => $job->orderId === $order->id);
        Queue::assertPushedOn('telegram-outbound', SendAdminOrderPendingNotification::class, fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) ($r['chat_id'] ?? '') === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Checkout belum bayar'));
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertNull($order->fresh()->manual_checkout_admin_notified_at);
        $this->assertNull($order->fresh()->admin_pending_order_notified_at);
    }

    public function test_manual_checkout_notification_retry_does_not_resend_customer_photo(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'QR_MANUAL', 'manual');
        $this->fakeTelegramHttp();

        (new SendManualCheckoutNotifications($order->id))->handle(app(TelegramCheckoutIntents::class));

        $order = $order->fresh();
        $this->assertNotNull($order->manual_checkout_customer_notified_at);
        $this->assertNull($order->manual_checkout_admin_notified_at);

        (new SendManualCheckoutNotifications($order->id))->handle(app(TelegramCheckoutIntents::class));

        $this->assertNull($order->fresh()->manual_checkout_admin_notified_at);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat'));
        $photoSends = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        $this->assertCount(1, $photoSends);
    }

    public function test_manual_checkout_notification_retry_skips_started_customer_effect_after_worker_crash(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'QR_MANUAL', 'manual');
        $order->forceFill(['manual_checkout_customer_notification_started_at' => now()])->save();
        $this->fakeTelegramHttp();

        (new SendManualCheckoutNotifications($order->id))->handle(app(TelegramCheckoutIntents::class));

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat'));
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertNull($order->fresh()->manual_checkout_admin_notified_at);
    }

    public function test_manual_checkout_dogfood_timing_reports_each_qr_step(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.dogfood_timing.enabled', true);
        $logs = [];
        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (string $event, array $context = []) use (&$logs) {
            $logs[] = [$event, $context];

            return true;
        });
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(172, 'cb-manual-timing', 'checkout'))->assertOk();

        foreach ([
            'order_checkout',
            'manual_payment_instructions',
            'manual_qr_file_read',
            'manual_customer_send_photo',
            'admin_pending_order_notification',
        ] as $step) {
            $this->assertManualCheckoutTimingStepWasLogged($logs, $step);
        }
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
            'method' => 'QR_MANUAL',
        ]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '12345')
            && str_contains($r->body(), 'Bayar via: QR Manual')
            && str_contains($r->body(), 'Premium Account'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout belum bayar'));
    }

    public function test_saved_pakasir_mode_with_missing_provider_readiness_falls_back_to_manual_checkout(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir.slug', '');
        config()->set('services.pakasir.api_key', '');
        config()->set('services.pakasir.checkout_flow', 'hosted');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'hosted',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Fallback Product', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(172, 'cb-pakasir-unready', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
        ]);
        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
            'provider' => 'pakasir',
        ]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran otomatis sedang tidak tersedia')
            && str_contains((string) ($r['text'] ?? ''), 'dialihkan ke QR manual')
            && ! str_contains((string) ($r['text'] ?? ''), 'Pakasir'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), 'Bayar via: QR Manual')
            && str_contains($r->body(), 'Fallback Product'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout belum bayar')
            && str_contains($r['text'], 'Metode: QR Manual'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'app.pakasir.test'));
    }

    public function test_saved_pakasir_mode_with_invalid_checkout_flow_falls_back_to_manual_checkout(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir.slug', 'sedulur-digital');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        config()->set('services.pakasir.checkout_flow', 'hosted');
        Queue::fake([SendManualCheckoutNotifications::class]);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => 'inline_qris',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Invalid Flow Product', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(173, 'cb-pakasir-invalid-flow', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
        ]);
        $this->assertDatabaseMissing('payments', [
            'order_id' => $order->id,
            'provider' => 'pakasir',
        ]);
        Queue::assertPushedOn('telegram-outbound', SendManualCheckoutNotifications::class, fn (SendManualCheckoutNotifications $job): bool => $job->orderId === $order->id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran otomatis sedang tidak tersedia')
            && str_contains((string) ($r['text'] ?? ''), 'dialihkan ke QR manual')
            && ! str_contains((string) ($r['text'] ?? ''), 'Pakasir'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
    }

    public function test_checkout_callback_in_persisted_pakasir_hosted_flow_sends_qr_with_direct_payment_button(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false, 'checkout_flow' => 'api_qris']);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'pakasir_checkout_flow' => 'hosted', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'price_amount' => 15000, 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 2);

        $this->postTelegramWebhook($this->callbackUpdate(18, 'cb-pakasir', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'pakasir', 'method' => 'hosted', 'amount' => 30000]);
        $this->assertNotEmpty($payment->merchant_ref);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/api/transactioncreate/qris'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'https://app.pakasir.test/pay/sedulur-digital/30000?order_id='.rawurlencode($payment->merchant_ref)));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard'));
    }

    public function test_checkout_callback_in_persisted_pakasir_native_qris_flow_creates_api_qris_payment_and_sends_payment_number_qr(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false, 'checkout_flow' => 'hosted']);
        $this->bindInspectableQrRenderer();
        $paymentNumber = '00020101021226610016ID.CO.QRIS.NATIVE92';
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactioncreate/qris' => fn ($request) => Http::response([
                'payment' => [
                    'project' => 'sedulur-digital',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 103,
                    'total_payment' => $request['amount'] + 103,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => '2026-07-04T16:30:00Z',
                ],
            ], 200),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'pakasir_checkout_flow' => 'api_qris', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'price_amount' => 15000, 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 2);

        $this->postTelegramWebhook($this->callbackUpdate(19, 'cb-pakasir-native', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $hostedUrl = 'https://app.pakasir.test/pay/sedulur-digital/30000?order_id='.rawurlencode($payment->merchant_ref);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'pakasir',
            'method' => 'api_qris',
            'amount' => 30000,
            'provider_fee' => 103,
            'provider_total_payment' => 30103,
            'provider_payment_method' => 'qris',
            'provider_payment_number_hash' => hash('sha256', $paymentNumber),
        ]);
        $this->assertSame($paymentNumber, $payment->fresh()->provider_payment_number);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/transactioncreate/qris')
            && $r['project'] === 'sedulur-digital'
            && $r['order_id'] === $payment->merchant_ref
            && $r['amount'] === 30000
            && $r['api_key'] === 'pakasir-key');
        $photoSends = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        $this->assertCount(1, $photoSends);
        $photoRequest = $photoSends->first()[0];
        $photoBody = $photoRequest->body();

        $this->assertStringContainsString('qr-source:'.$paymentNumber, $photoBody);
        $this->assertStringNotContainsString('qr-source:'.$hostedUrl, $photoBody);
        $this->assertStringContainsString('Total produk: Rp30.000', $photoBody);
        $this->assertStringContainsString('Biaya QRIS: Rp103', $photoBody);
        $this->assertStringContainsString('Total bayar QRIS: Rp30.103', $photoBody);
        $this->assertStringContainsString('Batas bayar QRIS:', $photoBody);
        $this->assertStringContainsString('Tidak perlu buka browser', $photoBody);
        $this->assertStringContainsString('Bayar via Website', $photoBody);
        $this->assertStringContainsString('app.pakasir.test\\/pay\\/sedulur-digital\\/30000', $photoBody);
        $this->assertStringNotContainsString('Pakasir', $photoBody);
        $this->assertStringContainsString('Cek Pembayaran', $photoBody);
        $this->assertStringContainsString('order:'.$order->invoice_number, $photoBody);
        $this->assertStringContainsString('Batalkan Order', $photoBody);
        $this->assertStringContainsString('buyer:cancel-pending:'.$order->id, $photoBody);
    }

    public function test_buyer_cancel_callback_cancels_own_pending_native_pakasir_order_and_releases_stock(): void
    {
        $this->fakeTelegramHttp();
        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $otherBuyer = Customer::factory()->create(['telegram_id' => 67890, 'username' => 'other_buyer']);
        $product = Product::factory()->create(['name' => 'Cancelable Native QRIS', 'price_amount' => 15000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cancelable@example.test|pass']);
        $otherProduct = Product::factory()->create(['name' => 'Other Native QRIS', 'price_amount' => 18000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $otherProduct->id, 'secret' => 'other@example.test|pass']);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($buyer->carts()->firstOrFail(), 'hosted', 'pakasir');
        $order->payments()->firstOrFail()->update(['provider_payment_method' => 'qris']);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($otherBuyer), $otherProduct, 1);
        $otherOrder = app(OrderCheckoutService::class)->checkout($otherBuyer->carts()->firstOrFail(), 'hosted', 'pakasir');
        $otherOrder->payments()->firstOrFail()->update(['provider_payment_method' => 'qris']);

        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, $otherProduct->fresh()->reserved_stock);
        $this->assertNotNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);

        $this->postTelegramWebhook($this->callbackUpdate(21, 'cb-buyer-cancel', 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Failed, $order->payments()->firstOrFail()->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);
        $this->assertSame(OrderStatus::PendingPayment, $otherOrder->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $otherOrder->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $otherOrder->payments()->firstOrFail()->fresh()->status);
        $this->assertSame(1, $otherProduct->fresh()->reserved_stock);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'cb-buyer-cancel'
            && $r['text'] === 'Order dibatalkan dan stok sudah dilepas.');
    }

    public function test_persisted_native_qris_create_failure_sends_hosted_fallback_and_keeps_payment_pending(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false, 'checkout_flow' => 'hosted']);
        $this->bindInspectableQrRenderer();
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactioncreate/qris' => Http::response(['message' => 'provider down'], 500),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'pakasir', 'pakasir_checkout_flow' => 'api_qris', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'price_amount' => 15000, 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(20, 'cb-pakasir-native-fallback', 'checkout'))->assertOk();

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $hostedUrl = 'https://app.pakasir.test/pay/sedulur-digital/15000?order_id='.rawurlencode($payment->merchant_ref);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('api_qris', $payment->method);
        $this->assertNull($payment->provider_payment_number);
        $this->assertNull($payment->provider_created_at);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/transactioncreate/qris'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), 'qr-source:'.$hostedUrl)
            && str_contains($r->body(), 'Checkout QRIS dialihkan')
            && str_contains($r->body(), 'Gunakan QR/link website di bawah untuk lanjut bayar.')
            && str_contains($r->body(), 'Order tetap pending')
            && ! str_contains($r->body(), 'Pakasir')
            && str_contains($r->body(), 'Bayar via Website')
            && str_contains($r->body(), $hostedUrl));
    }

    public function test_expired_callback_answer_does_not_block_callback_business_action(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
            ], 400),
        ]);
        $product = Product::factory()->create(['name' => 'Callback Safe', 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(9401, 'expired-callback', 'buy:'.$product->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pilih jumlah')
            && str_contains($r['text'], 'Callback Safe')
            && str_contains(json_encode($r->data()), 'qty:'.$product->id.':1'));
    }

    private function cartConfirmationMessagesFor(string $productName, int $quantity)
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), '🛒 Masuk keranjang')
            && str_contains((string) ($request['text'] ?? ''), $productName)
            && str_contains((string) ($request['text'] ?? ''), 'Jumlah: '.$quantity)
            && str_contains(json_encode($request->data()), 'checkout')
            && str_contains(json_encode($request->data()), 'Lanjut Checkout'));
    }

    private function bindInspectableQrRenderer(): void
    {
        $this->app->instance(TelegramQrCodeRenderer::class, new class extends TelegramQrCodeRenderer
        {
            public function png(string $content): string
            {
                return 'qr-source:'.$content;
            }
        });
    }

    private function assertManualCheckoutTimingStepWasLogged(array $logs, string $step): void
    {
        foreach ($logs as [$event, $context]) {
            if ($event === 'dogfood.telegram.checkout_step' && ($context['step'] ?? null) === $step) {
                $this->assertSame('manual', $context['payment_mode']);
                $this->assertSame('ok', $context['status']);
                $this->assertArrayHasKey('duration_ms', $context);
                $this->assertArrayHasKey('db_query_count', $context);
                $this->assertArrayHasKey('db_query_total_ms', $context);

                return;
            }
        }

        $this->fail("Expected dogfood manual checkout timing step [{$step}] was not logged.");
    }
}
