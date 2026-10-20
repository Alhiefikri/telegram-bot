<?php

namespace Tests\Feature\Telegram;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramBinanceRailSelectionSession;
use App\Services\Telegram\TelegramCartIntents;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramIntentSession;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\TelegramBotTestSupport;

class CartCheckoutTest extends TelegramBotTestSupport
{
    public function test_pakasir_checkout_instruction_job_releases_claim_after_send_failure_and_retries(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::PendingPayment,
            'manual_checkout_customer_notified_at' => null,
        ]);
        Payment::factory()->for($order)->create(['provider' => 'pakasir']);

        $failingIntents = $this->mock(TelegramCheckoutIntents::class);
        $failingIntents->shouldReceive('sendPakasirPaymentInstructions')
            ->once()
            ->andThrow(new RuntimeException('Telegram sendPhoto temporarily failed.'));

        $job = new SendPakasirCheckoutInstructions($order->id);

        try {
            $job->handle($failingIntents);
            $this->fail('The first outbound attempt should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Telegram sendPhoto temporarily failed.', $e->getMessage());
        }

        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);

        TelegramOutboundEffect::query()->sole()->forceFill([
            'next_attempt_at' => now()->subSecond(),
        ])->save();

        $successfulIntents = $this->mock(TelegramCheckoutIntents::class);
        $successfulIntents->shouldReceive('sendPakasirPaymentInstructions')
            ->once()
            ->andReturn(['ok' => true, 'result' => ['message_id' => 123456]]);
        $job->handle($successfulIntents);

        $this->assertNotNull($order->fresh()->manual_checkout_customer_notified_at);
    }

    public function test_checkout_callback_without_active_cart_and_duplicate_checkout_do_not_create_empty_carts(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(1950, 'checkout-empty', 'checkout', [], 7100))
            ->assertOk();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Cart::count());

        $customer = Customer::factory()->create(['telegram_id' => 7101, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Checkout Product', 'price_amount' => 15000, 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'checkout-secret-1']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'checkout-secret-2']);
        $carts = app(CartService::class);
        $carts->addItem($carts->activeCartFor($customer), $product, 1);
        $this->assertSame(1, Cart::where('status', CartStatus::Active)->count());

        $this->postTelegramWebhook($this->callbackUpdate(1952, 'checkout-first', 'checkout', [], 7101, 'private', 8100))
            ->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1953, 'checkout-qris', 'checkout:current', [], 7101, 'private', 8100))
            ->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1954, 'checkout-duplicate', 'checkout:current', [], 7101, 'private', 8100))
            ->assertOk();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame(0, Cart::where('status', CartStatus::Active)->count());
    }

    public function test_checkout_revalidates_changed_stock_and_prompts_cart_update(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Berubah', 'stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $product->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();

        $this->postTelegramWebhook($this->callbackUpdate(1954, 'checkout-changed-stock', 'checkout'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1955, 'checkout-changed-stock-confirm', 'checkout:current'))->assertOk();

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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'reserved_stock' => 0]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(17, 'cb3', 'checkout', ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(18, 'cb3-qris', 'checkout:current', ['username' => 'buyer']))->assertOk();
        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual', 'method' => 'QR_MANUAL', 'provider_reference' => null]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '12345')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat')
            && str_contains($r->body(), 'Bayar via: QR Manual')
            && str_contains($r->body(), 'Scan QR di atas dan bayar sesuai nominal')
            && str_contains($r->body(), 'manual:paid:'.$order->id)
            && str_contains($r->body(), 'buyer:cancel-pending:'.$order->id)
            && ! str_contains($r->body(), 'Nominal wajib sama persis')
            && ! str_contains($r->body(), '/payment')
            && ! str_contains($r->body(), 'GPT Plus')
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

    public function test_store_balance_cart_checkout_shows_balance_rail_and_finishes_paid_without_provider_instructions(): void
    {
        $this->fakeTelegramHttp();
        config(['services.telegram.admin_telegram_chat_id' => '999123']);
        $customer = Customer::factory()->create(['telegram_id' => 12348, 'username' => 'balance-buyer', 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Balance Product', 'price_amount' => 15_000, 'stock' => 2, 'reserved_stock' => 0]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            20_000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            '12348',
            'balance_top_up:12348:credit',
        );

        $this->postTelegramWebhook($this->callbackUpdate(30, 'balance-selector', 'checkout', [], 12348, 'private', 9001))
            ->assertOk();
        $selectorPayload = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12348')->first()[0]->data();
        $selectorJson = json_encode($selectorPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('checkout:balance', $selectorJson);
        $this->postTelegramWebhook($this->callbackUpdate(31, 'balance-checkout', 'checkout:balance', [], 12348, 'private', 9001))
            ->assertOk();
        $this->assertDatabaseCount('orders', 0);
        $wallet = Http::recorded(fn ($request): bool => str_contains(json_encode($request->data()), 'checkout:wallet:idr:'))->last()[0]->data();
        $callback = collect($wallet['reply_markup']['inline_keyboard'])->flatten(1)->first(
            fn (array $button): bool => str_starts_with($button['callback_data'] ?? '', 'checkout:wallet:idr:'))['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(33, 'idr-pay', $callback, [], 12348, 'private', 123457))->assertOk();

        $order = Order::query()->sole();
        $payment = $order->payments()->sole();
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('store_balance', $payment->provider);
        $this->assertSame('STORE_BALANCE', $payment->method);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(5_000, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseHas('store_balance_entries', [
            'kind' => StoreBalanceEntryKind::PurchaseDebit->value,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
            'amount' => 15_000,
        ]);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame(ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING, $review->reason);
        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $review->state);
        $this->postTelegramWebhook($this->callbackUpdate(32, 'balance-checkout-replay', 'checkout:balance', [], 12348, 'private', 9001))
            ->assertOk();
        $this->assertSame(1, ManualFulfillmentReview::where('payment_id', $payment->id)->count());
        $this->assertSame(1, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)
            ->where('source_id', $review->id)->count());
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) ($request['body'] ?? ''), 'Scan the QR'));
    }

    public function test_cart_add_confirmation_shows_tiers_and_next_tier_projection(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12349, 'locale' => 'en']);
        $product = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 23_000, 'usdt_price_amount' => null],
        ])->create([
            'name' => 'Tiered Cart Product',
            'price_amount' => 25_000,
            'stock' => 4,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->assertTrue(app(TelegramCartIntents::class)->addProductToCart(
            $customer,
            (string) $customer->telegram_id,
            $product->id,
            1,
        ));

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Tiered Cart Product'))->last();
        $this->assertNotNull($request);
        $text = (string) $request[0]->data()['text'];
        $this->assertStringContainsString('🛍️ Tiered Cart Product', $text);
        $this->assertStringContainsString('Subtotal: Rp25.000', $text);
        $this->assertStringContainsString('Base price: Rp25.000/unit', $text);
        $this->assertStringContainsString('Wholesale 2+: Rp23.000/unit', $text);
        $this->assertStringContainsString('🔥 Add 1 more units to unlock wholesale pricing.', $text);
        $this->assertStringContainsString('Total for 2 units: Rp46.000', $text);
    }

    public function test_payment_choice_preview_quotes_mixed_cart_tiers_and_effective_usdt_total(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12350, 'locale' => 'en']);
        $first = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 2, 'price_amount' => 18_000, 'usdt_price_amount' => 3_000_000],
        ])->create([
            'name' => 'Mixed First',
            'price_amount' => 25_000,
            'usdt_price_amount' => 3_250_000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $second = Product::factory()->withQuantityPriceTiers([
            ['minimum_quantity' => 3, 'price_amount' => 9_000, 'usdt_price_amount' => 1_800_000],
        ])->create([
            'name' => 'Mixed Second',
            'price_amount' => 10_000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $first, 2);
        app(CartService::class)->addItem($cart, $second, 3);

        $this->postTelegramWebhook($this->callbackUpdate(
            123501,
            'mixed-payment-preview',
            'checkout',
            [],
            12350,
            'private',
            9010,
        ))->assertOk();

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12350')->last();
        $this->assertNotNull($request);
        $payload = $request[0]->data();
        $text = (string) $payload['text'];
        $keyboard = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Mixed First × 2 · Rp18.000/unit · 💎 3 USDT/unit · Total Rp36.000 · 💎 6 USDT', $text);
        $this->assertStringContainsString('Mixed Second × 3 · Rp9.000/unit · 💎 1.8 USDT/unit · Total Rp27.000 · 💎 5.4 USDT', $text);
        $this->assertStringContainsString('Wholesale 2+: Rp18.000/unit · 💎 3 USDT/unit', $text);
        $this->assertStringContainsString('Wholesale 3+: Rp9.000/unit · 💎 1.8 USDT/unit', $text);
        $this->assertStringContainsString('Total: Rp63.000 · 💎 11.4 USDT', $text);
        $this->assertStringContainsString('QRIS · Rp63.000', $keyboard);
        $this->assertStringContainsString('USDT · 11.4 USDT', $keyboard);
        $this->assertStringNotContainsString('checkout.payment_method', $text);
        $this->assertSame(0, Order::count());
    }

    public function test_manual_paid_button_edits_qr_caption_to_proof_instructions_without_new_buyer_message(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12346, 'username' => 'buyer', 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Manual Proof Product', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(18, 'checkout-manual-proof', 'checkout', [], 12346))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(181, 'checkout-manual-proof-qris', 'checkout:current', [], 12346))->assertOk();

        $order = Order::query()->sole();
        $this->postTelegramWebhook($this->callbackUpdate(
            19,
            'manual-paid',
            'manual:paid:'.$order->id,
            [],
            12346,
            'private',
            123456,
        ))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertNull($order->payments()->sole()->fresh()->payment_proof_submitted_at);
        $editMessages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/editMessageCaption')
            && (string) ($request['chat_id'] ?? '') === '12346');
        $this->assertCount(1, $editMessages);
        $edited = $editMessages->last()[0]->data();
        $this->assertSame(123456, $edited['message_id']);
        $this->assertStringContainsString('Send payment proof', (string) $edited['caption']);
        $this->assertStringContainsString('/payment', (string) $edited['caption']);
        $this->assertStringNotContainsString('Scan the QR above', (string) $edited['caption']);
        $this->assertSame('buyer:cancel-pending:'.$order->id, $edited['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $buyerMessages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12346');
        $this->assertCount(1, $buyerMessages);
        $this->assertStringContainsString('Choose a payment method', (string) $buyerMessages->first()[0]->data()['text']);
    }

    public function test_manual_paid_button_rejects_an_expired_order_without_editing_or_sending(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12347, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Expired Proof Product', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(20, 'expired-checkout', 'checkout', [], 12347))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(201, 'expired-checkout-qris', 'checkout:current', [], 12347))->assertOk();
        $order = Order::query()->sole();
        $order->update(['expires_at' => now()->subMinute()]);

        $this->postTelegramWebhook($this->callbackUpdate(
            21,
            'expired-manual-paid',
            'manual:paid:'.$order->id,
            [],
            12347,
            'private',
            123456,
        ))->assertOk();

        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/editMessageCaption')));
        $buyerMessages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12347');
        $this->assertCount(1, $buyerMessages);
        $this->assertStringContainsString('Choose a payment method', (string) $buyerMessages->first()[0]->data()['text']);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === 'expired-manual-paid'
            && str_contains((string) ($request['text'] ?? ''), 'no longer awaiting payment'));
    }

    #[Group('postgres-release')]
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
        $this->postTelegramWebhook($this->callbackUpdate(174, 'cb-manual-queued-qris', 'checkout:current'))->assertOk();

        $order = Order::firstOrFail();
        Queue::assertPushedOn('telegram-outbound', SendManualCheckoutNotifications::class, fn (SendManualCheckoutNotifications $job): bool => $job->orderId === $order->id);
        Queue::assertPushedOn('notifications', SendAdminOrderPendingNotification::class, fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id
            && $job->effectId !== null);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '✅ Checkout berhasil dibuat'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) ($r['chat_id'] ?? '') === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Checkout belum bayar'));
        $this->assertNull($order->fresh()->manual_checkout_customer_notified_at);
        $this->assertNull($order->fresh()->manual_checkout_admin_notified_at);
        $this->assertNull($order->fresh()->admin_pending_order_notified_at);
    }

    public function test_binance_checkout_enqueues_instructions_without_inline_message_send(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        Queue::fake([SendBinanceCheckoutInstructions::class, SendAdminOrderPendingNotification::class]);
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['name' => 'AI Premium', 'stock' => 5, 'usdt_price_amount' => 2_500_000]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(174, 'cb-binance-selector', 'checkout:binance', [], 12345, 'private', 8100))->assertOk();
        $selection = app(TelegramBinanceRailSelectionSession::class)->get($customer);
        $this->assertNotNull($selection);
        $this->postTelegramWebhook($this->callbackUpdate(
            175,
            'cb-binance-queued',
            'checkout:binance:rail:binance_pay_id:'.$selection['nonce'],
            [],
            12345,
            'private',
            123457,
        ))->assertOk();

        $order = Order::firstOrFail();
        Queue::assertPushedOn('telegram-outbound', SendBinanceCheckoutInstructions::class, fn (SendBinanceCheckoutInstructions $job): bool => $job->orderId === $order->id);
        Queue::assertPushedOn('notifications', SendAdminOrderPendingNotification::class, fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id
            && $job->effectId !== null);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Binance Pay ID'));
    }

    public function test_binance_pending_checkout_notification_shows_usdt_total_to_admin(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'binance_pay_enabled' => true, 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'AI Premium', 'price_amount' => 30000, 'usdt_price_amount' => 2_500_000, 'stock' => 5]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());

        (new SendAdminOrderPendingNotification($order->id))->handle(app(TelegramOutboundEffectService::class));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && str_contains((string) ($request['text'] ?? ''), 'Metode: Binance Pay')
            && str_contains((string) ($request['text'] ?? ''), 'Total: 2.5 USDT')
            && ! str_contains((string) ($request['text'] ?? ''), 'Total: Rp30.000'));
    }

    public function test_manual_checkout_notification_retry_does_not_resend_customer_photo(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
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

    public function test_manual_checkout_notification_skips_an_active_customer_effect_owned_by_another_worker(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $this->fakeTelegramHttp();
        TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT)
            ->where('source_id', (string) $order->id)
            ->sole()
            ->forceFill([
                'state' => TelegramOutboundEffectState::Sending,
                'claim_token' => 'active-worker-token',
                'lease_until' => now()->addMinute(),
            ])
            ->save();

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
        $this->postTelegramWebhook($this->callbackUpdate(173, 'cb-manual-timing-qris', 'checkout:current'))->assertOk();

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

    public function test_active_payment_setting_overrides_configured_provider_default(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Premium Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(171, 'cb-manual-override', 'checkout'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(172, 'cb-manual-override-qris', 'checkout:current'))->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
        ]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && str_contains($r->body(), '12345')
            && str_contains($r->body(), 'Bayar via: QR Manual')
            && str_contains($r->body(), 'Scan QR di atas dan bayar sesuai nominal'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains($r['text'], 'Checkout belum bayar'));
    }

    public function test_buyer_cancel_callback_cancels_own_pending_native_pakasir_order_and_releases_stock(): void
    {
        $payment = null;
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => function () use (&$payment) {
                return Http::response(['transaction' => [
                    'project' => 'sedulur-digital',
                    'amount' => $payment->amount,
                    'order_id' => $payment->merchant_ref,
                    'payment_method' => 'qris',
                    'status' => 'pending',
                ]]);
            },
            'https://app.pakasir.test/api/transactioncancel*' => Http::response([
                'transaction' => ['status' => 'cancelled'],
            ]),
        ]);
        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $otherBuyer = Customer::factory()->create(['telegram_id' => 67890, 'username' => 'other_buyer']);
        $product = Product::factory()->create(['name' => 'Cancelable Native QRIS', 'price_amount' => 15000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cancelable@example.test|pass']);
        $otherProduct = Product::factory()->create(['name' => 'Other Native QRIS', 'price_amount' => 18000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $otherProduct->id, 'secret' => 'other@example.test|pass']);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product, 1);
        $order = $this->legacyPakasirOrder($buyer->carts()->firstOrFail());
        $payment = $order->payments()->firstOrFail();
        $payment->update([
            'provider_payment_method' => 'qris',
            'provider_payment_number' => '00020101021226610016ID.CO.QRIS.CANCEL99',
            'provider_payment_number_hash' => hash('sha256', 'cancel'),
            'provider_created_at' => now(),
            'provider_fee' => 0,
            'provider_total_payment' => $payment->amount,
        ]);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($otherBuyer), $otherProduct, 1);
        $otherOrder = $this->legacyPakasirOrder($otherBuyer->carts()->firstOrFail());
        $otherOrder->payments()->firstOrFail()->update(['provider_payment_method' => 'qris']);

        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, $otherProduct->fresh()->reserved_stock);
        $this->assertNotNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);

        $this->postTelegramWebhook($this->callbackUpdate(21, 'cb-buyer-cancel', 'buyer:cancel-pending:'.$order->id, messageId: 321))->assertOk();

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
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/deleteMessage')
            && (string) $r['chat_id'] === '12345'
            && $r['message_id'] === 321);
    }

    public function test_buyer_cancel_callback_deletes_binance_checkout_photo_and_releases_stock(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['binance_pay_enabled' => true, 'is_active' => true]);
        $buyer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create([
            'name' => 'Cancelable Binance Checkout',
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cancelable-binance@example.test|pass']);
        $cart = app(CartService::class)->activeCartFor($buyer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());

        $this->assertSame(1, $product->fresh()->reserved_stock);

        $this->postTelegramWebhook($this->callbackUpdate(22, 'cb-binance-cancel', 'buyer:cancel-pending:'.$order->id, messageId: 654))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) $request['chat_id'] === '12345'
            && $request['message_id'] === 654);
    }

    public function test_expired_callback_answer_does_not_block_callback_business_action(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
            ], 400),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Callback Safe', 'stock' => 2, 'reserved_stock' => 0, 'is_active' => true]);

        $this->postTelegramWebhook($this->callbackUpdate(9401, 'expired-callback', 'product:'.$product->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Jumlah: 1')
            && str_contains($r['text'], 'Callback Safe')
            && str_contains(json_encode($r->data()), 'detail:qty:+:'.$product->id.':'));
    }

    public function test_product_detail_copy_follows_each_locale_for_quantity_and_maintenance(): void
    {
        $this->fakeTelegramHttp();
        $locales = [
            'id' => [
                'chat_id' => 45201,
                'quantity' => 'Jumlah: 1',
                'custom' => 'Masukkan jumlah kustom',
                'subtotal' => 'Total: Rp25.000',
                'qris' => 'QRIS · Rp25.000',
                'maintenance' => 'Checkout sedang dalam maintenance',
                'status' => 'Cek Status Checkout',
            ],
            'en' => [
                'chat_id' => 45202,
                'quantity' => 'Quantity: 1',
                'custom' => 'Enter custom quantity',
                'subtotal' => 'Total: Rp25.000',
                'qris' => 'QRIS · Rp25.000',
                'maintenance' => 'Checkout is under maintenance',
                'status' => 'Check Checkout Status',
            ],
            'ms' => [
                'chat_id' => 45203,
                'quantity' => 'Kuantiti: 1',
                'custom' => 'Masukkan kuantiti tersuai',
                'subtotal' => 'Jumlah: Rp25.000',
                'qris' => 'QRIS · Rp25.000',
                'maintenance' => 'Checkout sedang diselenggara',
                'status' => 'Semak Status Checkout',
            ],
        ];

        foreach ($locales as $index => $locale) {
            $customer = Customer::factory()->create(['telegram_id' => $locale['chat_id'], 'locale' => $index]);
            $product = Product::factory()->create([
                'name' => 'Detail Locale '.$index,
                'price_amount' => 25000,
                'stock' => 5,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);
            $from = ['id' => $locale['chat_id'], 'first_name' => 'Detail Tester'];

            $this->postTelegramWebhook($this->callbackUpdate(
                45210 + $locale['chat_id'],
                'detail-'.$index,
                'product:'.$product->id,
                $from,
                $locale['chat_id'],
            ))->assertOk();
            $selection = app(TelegramIntentSession::class)->get($customer);
            $this->assertNotNull($selection);

            $this->postTelegramWebhook($this->callbackUpdate(
                45220 + $locale['chat_id'],
                'custom-'.$index,
                'detail:qty:manual:'.$product->id.':'.$selection['interactionToken'],
                $from,
                $locale['chat_id'],
            ))->assertOk();
            $awaiting = app(TelegramIntentSession::class)->get($customer);
            $this->assertNotNull($awaiting);
            $this->postTelegramWebhook($this->messageUpdate(
                45230 + $locale['chat_id'],
                'not-a-number',
                $from,
                $locale['chat_id'],
            ))->assertOk();
            $this->postTelegramWebhook($this->callbackUpdate(
                45240 + $locale['chat_id'],
                'cancel-'.$index,
                'detail:qty:cancel:'.$product->id.':'.$awaiting['interactionToken'],
                $from,
                $locale['chat_id'],
            ))->assertOk();

            CheckoutMaintenanceSetting::query()->firstOrFail()->update([
                'is_active' => true,
                'public_note' => 'KEEP admin note',
                'version' => 1,
                'activated_at' => now(),
            ]);
            $maintenanceProduct = Product::factory()->create([
                'name' => 'Maintenance Detail '.$index,
                'price_amount' => 15000,
                'stock' => 1,
                'reserved_stock' => 0,
                'is_active' => true,
            ]);
            $this->postTelegramWebhook($this->callbackUpdate(
                45250 + $locale['chat_id'],
                'maintenance-'.$index,
                'product:'.$maintenanceProduct->id,
                $from,
                $locale['chat_id'],
            ))->assertOk();
            CheckoutMaintenanceSetting::query()->firstOrFail()->update([
                'is_active' => false,
                'public_note' => null,
                'version' => 2,
                'activated_at' => null,
            ]);

            $payloads = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $locale['chat_id'])
                ->map(fn (array $record): array => $record[0]->data());
            $copy = $payloads->map(fn (array $payload): string => (string) ($payload['text'] ?? '')."\n".json_encode($payload['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE))->implode("\n");

            foreach (['quantity', 'custom', 'subtotal', 'qris', 'maintenance', 'status'] as $key) {
                $this->assertStringContainsString($locale[$key], $copy);
            }
            $this->assertStringContainsString('Detail Locale '.$index, $copy);
            $this->assertStringContainsString('Maintenance Detail '.$index, $copy);
            $this->assertStringContainsString('KEEP admin note', $copy);
        }
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
