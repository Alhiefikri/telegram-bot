<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class OrderStatusHistoryTest extends TelegramBotTestSupport
{
    public function test_batal_abandons_cart_and_status_reports_latest_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        app(CartService::class)->activeCartFor($customer);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $this->postTelegramWebhook($this->messageUpdate(115, '/batal'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(116, '/status'))->assertOk();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id, 'status' => 'abandoned']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], $order->invoice_number));
    }

    public function test_riwayat_lists_latest_customer_orders_with_detail_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $old = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OLD', 'created_at' => now()->subDay()]);
        $new = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-NEW', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid, 'created_at' => now()]);

        $this->postTelegramWebhook($this->messageUpdate(201, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat Pesanan')
            && strpos($r['text'], $new->invoice_number) < strpos($r['text'], $old->invoice_number)
            && str_contains($r['text'], 'Selesai / Dibayar')
            && str_contains(json_encode($r->data()), 'order:'.$new->invoice_number));
    }

    public function test_riwayat_fallback_when_no_orders(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(202, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat masih kosong')
            && str_contains($r['text'], 'Yuk lihat produk dulu'));
    }

    public function test_riwayat_hides_other_customer_orders(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OWN']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER']);

        $this->postTelegramWebhook($this->messageUpdate(203, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], $own->invoice_number)
            && ! str_contains($r['text'], 'INV-OTHER'));
    }

    public function test_order_without_invoice_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(204, '/order'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik nomor invoice')
            && str_contains($r['text'], 'INV-'));
    }

    public function test_order_valid_detail_includes_items_payment_and_pending_instruction(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-DETAIL', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Canva Pro', 'quantity' => 2, 'subtotal' => 20000]);
        Payment::factory()->create(['order_id' => $order->id, 'method' => 'hosted', 'provider' => 'pakasir', 'status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->messageUpdate(205, '/order INV-DETAIL'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Canva Pro x 2')
            && str_contains($r['text'], 'Pembayaran: QRIS (Menunggu pembayaran)')
            && str_contains($r['text'], 'Selesaikan pembayaran')
            && ! str_contains($r['text'], 'hosted')
            && ! str_contains($r['text'], 'Pakasir'));
    }

    public function test_order_command_from_supergroup_prompts_private_chat_without_leaking_paid_order_details(): void
    {
        $this->fakeTelegramHttp();
        $privateChatId = 12345;
        $groupChatId = -1001122334455;
        $invoice = 'INV-ISSUE-112-CMD';
        $productName = 'Issue 112 Vault Product';
        $credential = 'issue112-command@example.test | password: Secret-112-CMD';
        $customer = Customer::factory()->create(['telegram_id' => $privateChatId]);
        $this->paidCompletedOrderWithCredential($customer, $invoice, $productName, $credential);
        $sensitiveValues = [$invoice, 'paid', 'Pembayaran sudah diterima', $productName, $credential, 'Secret-112-CMD'];

        $this->postTelegramWebhook($this->messageUpdate(
            216,
            '/order '.$invoice,
            ['id' => $privateChatId, 'first_name' => 'Issue112 Buyer'],
            $groupChatId,
            'supergroup',
        ))->assertOk();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) ($r['chat_id'] ?? '') === (string) $groupChatId
            && $this->requestBodyContainsAny($r, $sensitiveValues));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) ($r['chat_id'] ?? '') === (string) $groupChatId
            && str_contains(strtolower((string) ($r['text'] ?? '')), 'chat pribadi')
            && ! $this->requestBodyContainsAny($r, $sensitiveValues));
    }

    public function test_order_callback_from_group_is_answered_privately_without_sending_sensitive_order_details(): void
    {
        $this->fakeTelegramHttp();
        $privateChatId = 12345;
        $groupChatId = -445566;
        $invoice = 'INV-ISSUE-112-CB';
        $productName = 'Issue 112 Callback Product';
        $credential = 'issue112-callback@example.test | password: Secret-112-CB';
        $customer = Customer::factory()->create(['telegram_id' => $privateChatId]);
        $this->paidCompletedOrderWithCredential($customer, $invoice, $productName, $credential);
        $sensitiveValues = [$invoice, 'paid', 'Pembayaran sudah diterima', $productName, $credential, 'Secret-112-CB'];

        $this->postTelegramWebhook($this->callbackUpdate(
            217,
            'cb-issue-112-order',
            'order:'.$invoice,
            ['id' => $privateChatId, 'first_name' => 'Issue112 Buyer'],
            $groupChatId,
            'group',
        ))->assertOk();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) ($r['chat_id'] ?? '') === (string) $groupChatId
            && $this->requestBodyContainsAny($r, $sensitiveValues));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && ($r['callback_query_id'] ?? null) === 'cb-issue-112-order'
            && str_contains(strtolower((string) ($r['text'] ?? '')), 'chat pribadi')
            && ! $this->requestBodyContainsAny($r, $sensitiveValues));
    }

    public function test_order_detail_names_pakasir_payment_as_generic_qris_instead_of_raw_hosted_method(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-NATIVE-QRIS', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'ChatGPT Plus', 'quantity' => 1, 'subtotal' => 30000]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'method' => 'hosted',
            'provider' => 'pakasir',
            'status' => PaymentStatus::Pending,
            'provider_payment_method' => 'qris',
            'provider_payment_number' => '00020101021226610016ID.CO.QRIS.NATIVE99',
            'provider_payment_number_hash' => hash('sha256', '00020101021226610016ID.CO.QRIS.NATIVE99'),
            'provider_created_at' => now(),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(214, '/order INV-NATIVE-QRIS'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Pembayaran: QRIS (Menunggu pembayaran)')
            && ! str_contains($r['text'], 'Pembayaran: hosted')
            && ! str_contains($r['text'], 'Pakasir'));
    }

    public function test_order_detail_refreshes_pending_pakasir_payment_from_transaction_detail(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $payment->update(['provider_payment_method' => 'qris']);
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => [
                'project' => 'sedulur-digital',
                'order_id' => $payment->merchant_ref,
                'amount' => $payment->amount,
                'status' => 'completed',
                'payment_method' => 'qris',
            ]]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(211, '/order '.$order->invoice_number))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertNotNull(PaymentEvent::firstOrFail()->processed_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Pembayaran sudah diterima'));
    }

    public function test_order_detail_status_refresh_is_rate_limited_per_payment(): void
    {
        config()->set('services.pakasir.status_refresh_rate_limit_per_minute', 1);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => [
                'project' => 'sedulur-digital',
                'order_id' => $payment->merchant_ref,
                'amount' => $payment->amount,
                'status' => 'pending',
            ]]),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(212, '/order '.$order->invoice_number))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(213, '/order '.$order->invoice_number))->assertOk();

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/api/transactiondetail')));
        $this->assertSame(1, PaymentEvent::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Cek status pembayaran dibatasi sementara'));
    }

    public function test_order_cannot_access_other_customer_order(): void
    {
        $this->fakeTelegramHttp();
        Order::factory()->create(['invoice_number' => 'INV-SECRET']);

        $this->postTelegramWebhook($this->messageUpdate(206, '/order INV-SECRET'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-SECRET'));
    }

    public function test_order_callback_valid_detail_and_manipulated_callback_cannot_leak_other_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CB', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $own->id, 'product_name' => 'Netflix']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER-CB']);

        $this->postTelegramWebhook($this->callbackUpdate(207, 'cb-order', 'order:INV-CB'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(208, 'cb-order-bad', 'order:INV-OTHER-CB'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'INV-CB')
            && str_contains($r['text'], 'Pembayaran sudah diterima'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-OTHER-CB'));
    }

    public function test_order_expired_payment_expiry_is_rendered_in_configured_app_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalAppTimezone = config('app.timezone');
        config()->set('app.timezone', 'Asia/Singapore');
        date_default_timezone_set('Asia/Singapore');

        try {
            $this->fakeTelegramHttp();
            $customer = Customer::factory()->create(['telegram_id' => 12345]);
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-SG-EXPIRY',
                'order_status' => OrderStatus::Expired,
                'payment_status' => PaymentStatus::Expired,
                'created_at' => CarbonImmutable::parse('2026-07-04 15:30:00', 'Asia/Singapore'),
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_name' => 'Singapore Timezone Fixture',
                'quantity' => 1,
                'subtotal' => 125000,
            ]);
            Payment::factory()->create([
                'order_id' => $order->id,
                'method' => 'hosted',
                'provider' => 'pakasir',
                'status' => PaymentStatus::Expired,
                'expired_at' => CarbonImmutable::parse('2026-07-04 16:45:00', 'Asia/Singapore'),
            ]);

            $this->postTelegramWebhook($this->messageUpdate(218, '/order INV-SG-EXPIRY'))->assertOk();

            $sentMessages = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) ($request['text'] ?? ''), 'INV-SG-EXPIRY'));
            $this->assertCount(1, $sentMessages);

            $text = (string) $sentMessages->first()[0]['text'];
            $this->assertStringContainsString('Detail Pesanan', $text);
            $this->assertStringContainsString('Berakhir: 04 Jul 2026 16:45 Asia/Singapore', $text);
            $this->assertStringContainsString('Dibuat: 04 Jul 2026 15:30 Asia/Singapore', $text);
            $this->assertStringNotContainsString('08:45', $text);
            $this->assertStringNotContainsString('WIB', $text);
        } finally {
            config()->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_order_detail_expired_or_cancelled_instructs_checkout_again(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-EXP', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CAN', 'order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed]);

        $this->postTelegramWebhook($this->messageUpdate(209, '/order INV-EXP'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(210, '/order INV-CAN'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'checkout ulang lewat /produk'));
    }

    #[DataProvider('customerLocaleCases')]
    public function test_status_uses_customer_locale_and_human_readable_order_and_payment_statuses(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-STATUS-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(301, '/status'))->assertOk();

        $text = $this->sentMessageContaining($order->invoice_number);
        $this->assertStringContainsString($copy['statusTitle'], $text);
        $this->assertStringContainsString('📌 '.$copy['orderStatusLabel'].' '.$copy['orderPending'], $text);
        $this->assertStringContainsString('💳 '.$copy['paymentStatusLabel'].' '.$copy['paymentPending'], $text);
        $this->assertStringContainsString($copy['historyCommand'], $text);
        $this->assertStringNotContainsString('pending_payment', $text);
        $this->assertStringNotContainsString($copy['otherStatusTitle'], $text);
    }

    #[DataProvider('customerLocaleCases')]
    public function test_history_alias_localizes_labels_statuses_buttons_and_dates(array $copy): void
    {
        config()->set('app.timezone', 'Asia/Jakarta');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-HISTORY-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'created_at' => CarbonImmutable::parse('2026-05-04 15:30:00', 'Asia/Jakarta'),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(302, $copy['historyCommand']))->assertOk();

        $request = $this->sentMessageRequestContaining($order->invoice_number);
        $text = (string) $request['text'];
        $this->assertStringContainsString($copy['historyTitle'], $text);
        $this->assertStringContainsString($copy['orderPending'].' / '.$copy['paymentPending'], $text);
        $this->assertStringContainsString($copy['shortDate'], $text);
        $this->assertSame($copy['detailButton'].' '.$order->invoice_number, $request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertSame('order:'.$order->invoice_number, $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null);
        $this->assertStringNotContainsString('pending_payment', $text);
        $this->assertStringNotContainsString($copy['otherHistoryTitle'], $text);
        $this->assertStringNotContainsString($copy['otherShortDate'], $text);
    }

    #[DataProvider('customerLocaleCases')]
    public function test_order_detail_localizes_statuses_dates_and_delivery_note_heading_without_translating_note_data(array $copy): void
    {
        $originalTimezone = date_default_timezone_get();
        config()->set('app.timezone', 'Asia/Jakarta');
        date_default_timezone_set('Asia/Jakarta');

        try {
            $this->fakeTelegramHttp();
            $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
            $product = Product::factory()->create(['name' => 'Locale Vault', 'delivery_note' => 'merchant-note::KEEP-OPAQUE']);
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-DETAIL-'.strtoupper($copy['locale']),
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'created_at' => CarbonImmutable::parse('2026-05-04 15:30:00', 'Asia/Jakarta'),
            ]);
            $item = OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'delivery_note' => 'merchant-note::KEEP-OPAQUE',
                'delivery_note_snapshotted' => true,
            ]);
            ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => 'locale@example.test|secret']);
            Payment::factory()->create([
                'order_id' => $order->id,
                'provider' => 'manual',
                'method' => 'manual',
                'status' => PaymentStatus::Paid,
                'paid_at' => CarbonImmutable::parse('2026-05-05 09:15:00', 'Asia/Jakarta'),
            ]);

            $this->postTelegramWebhook($this->messageUpdate(303, '/order '.$order->invoice_number))->assertOk();

            $text = $this->sentMessageContaining($order->invoice_number);
            $this->assertStringContainsString($copy['orderTitle'], $text);
            $this->assertStringContainsString('Status: '.$copy['orderCompleted'].' / '.$copy['paymentPaid'], $text);
            $this->assertStringContainsString($copy['createdLabel'].' '.$copy['createdDate'], $text);
            $this->assertStringContainsString($copy['paidLabel'].' '.$copy['paidDate'], $text);
            $this->assertStringContainsString($copy['paymentLabel'].' manual ('.$copy['paymentPaid'].')', $text);
            $this->assertStringContainsString($copy['deliveryNoteHeading'], $text);
            $this->assertStringContainsString('merchant-note::KEEP-OPAQUE', $text);
            if ($copy['locale'] === 'id') {
                $this->assertStringNotContainsString('completed / paid', strtolower($text));
            }
            $this->assertStringNotContainsString($copy['otherOrderTitle'], $text);
            $this->assertStringNotContainsString($copy['otherDeliveryNoteHeading'], $text);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('customerLocaleCases')]
    public function test_group_privacy_denials_follow_customer_locale_for_message_and_callback(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PRIVATE-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(304, '/order '.$order->invoice_number, ['id' => 12345], -100123, 'supergroup'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(305, 'privacy-'.$copy['locale'], 'order:'.$order->invoice_number, ['id' => 12345], -100123, 'supergroup'))->assertOk();

        $message = $this->sentMessageRequestForChat('-100123');
        $messageText = (string) $message['text'];
        $callbackText = $this->callbackAnswerText('privacy-'.$copy['locale']);
        $this->assertStringContainsString($copy['privacyMessage'], $messageText);
        $this->assertStringContainsString($copy['privacyCallback'], $callbackText);
        $this->assertStringNotContainsString($copy['otherPrivacyMessage'], $messageText);
        $this->assertStringNotContainsString($copy['otherPrivacyCallback'], $callbackText);
        $this->assertStringNotContainsString($order->invoice_number, $messageText);
    }

    #[DataProvider('customerLocaleCases')]
    public function test_buyer_can_cancel_own_pending_order_with_localized_confirmation(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CANCEL-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->callbackUpdate(306, 'cancel-success-'.$copy['locale'], 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Failed, $order->payments()->firstOrFail()->fresh()->status);
        $this->assertSame($copy['cancelSuccessAnswer'], $this->callbackAnswerText('cancel-success-'.$copy['locale']));
        $message = $this->sentMessageContaining($order->invoice_number);
        $this->assertStringContainsString($copy['cancelSuccessMessage'], $message);
        $this->assertStringNotContainsString($copy['otherCancelSuccessMessage'], $message);
    }

    #[DataProvider('customerLocaleCases')]
    public function test_stale_buyer_cancel_callback_preserves_order_and_uses_customer_locale(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-STALE-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        $this->postTelegramWebhook($this->callbackUpdate(307, 'cancel-stale-'.$copy['locale'], 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame($copy['cancelStaleAnswer'], $this->callbackAnswerText('cancel-stale-'.$copy['locale']));
        $message = $this->sentMessageRequestForChat('12345');
        $messageText = (string) $message['text'];
        $this->assertStringContainsString($copy['cancelStaleMessage'], $messageText);
        $this->assertStringContainsString('/status', $messageText);
        $this->assertStringNotContainsString($copy['otherCancelStaleMessage'], $messageText);
    }

    public static function customerLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'historyCommand' => '/riwayat',
                'statusTitle' => 'Status pesanan',
                'otherStatusTitle' => 'Order status',
                'orderStatusLabel' => 'Pesanan:',
                'paymentStatusLabel' => 'Pembayaran:',
                'orderPending' => 'Menunggu pembayaran',
                'paymentPending' => 'Menunggu pembayaran',
                'historyTitle' => 'Riwayat Pesanan Terakhir',
                'otherHistoryTitle' => 'Recent Order History',
                'detailButton' => '🧾 Detail',
                'shortDate' => '04 Mei 2026',
                'otherShortDate' => '04 May 2026',
                'orderTitle' => 'Detail Pesanan',
                'otherOrderTitle' => 'Order Details',
                'orderCompleted' => 'Selesai',
                'paymentPaid' => 'Dibayar',
                'createdLabel' => 'Dibuat:',
                'createdDate' => '04 Mei 2026 15:30 WIB',
                'paidLabel' => 'Dibayar:',
                'paidDate' => '05 Mei 2026 09:15 WIB',
                'paymentLabel' => 'Pembayaran:',
                'deliveryNoteHeading' => '📌 Catatan pengiriman:',
                'otherDeliveryNoteHeading' => '📌 Delivery note:',
                'privacyMessage' => 'Lanjut di chat pribadi',
                'otherPrivacyMessage' => 'Continue in a private chat',
                'privacyCallback' => 'lanjutkan di chat pribadi',
                'otherPrivacyCallback' => 'continue in a private chat',
                'cancelSuccessAnswer' => 'Order dibatalkan dan stok sudah dilepas.',
                'cancelSuccessMessage' => 'Pesanan dibatalkan',
                'otherCancelSuccessMessage' => 'Order cancelled',
                'cancelStaleAnswer' => 'Pesanan sudah tidak bisa dibatalkan.',
                'cancelStaleMessage' => 'Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.',
                'otherCancelStaleMessage' => 'Order not found or can no longer be cancelled.',
            ]],
            'English' => [[
                'locale' => 'en',
                'historyCommand' => '/history',
                'statusTitle' => 'Order status',
                'otherStatusTitle' => 'Status pesanan',
                'orderStatusLabel' => 'Order:',
                'paymentStatusLabel' => 'Payment:',
                'orderPending' => 'Awaiting payment',
                'paymentPending' => 'Awaiting payment',
                'historyTitle' => 'Recent Order History',
                'otherHistoryTitle' => 'Riwayat Pesanan Terakhir',
                'detailButton' => '🧾 Details',
                'shortDate' => '04 May 2026',
                'otherShortDate' => '04 Mei 2026',
                'orderTitle' => 'Order Details',
                'otherOrderTitle' => 'Detail Pesanan',
                'orderCompleted' => 'Completed',
                'paymentPaid' => 'Paid',
                'createdLabel' => 'Created:',
                'createdDate' => '04 May 2026 15:30 WIB',
                'paidLabel' => 'Paid:',
                'paidDate' => '05 May 2026 09:15 WIB',
                'paymentLabel' => 'Payment:',
                'deliveryNoteHeading' => '📌 Delivery note:',
                'otherDeliveryNoteHeading' => '📌 Catatan pengiriman:',
                'privacyMessage' => 'Continue in a private chat',
                'otherPrivacyMessage' => 'Lanjut di chat pribadi',
                'privacyCallback' => 'continue in a private chat',
                'otherPrivacyCallback' => 'lanjutkan di chat pribadi',
                'cancelSuccessAnswer' => 'Order cancelled and stock released.',
                'cancelSuccessMessage' => 'Order cancelled',
                'otherCancelSuccessMessage' => 'Pesanan dibatalkan',
                'cancelStaleAnswer' => 'This order can no longer be cancelled.',
                'cancelStaleMessage' => 'Order not found or can no longer be cancelled.',
                'otherCancelStaleMessage' => 'Pesanan tidak ditemukan atau sudah tidak bisa dibatalkan.',
            ]],
        ];
    }

    private function sentMessageContaining(string $needle): string
    {
        return (string) $this->sentMessageRequestContaining($needle)['text'];
    }

    private function sentMessageRequestContaining(string $needle): Request
    {
        $requests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), $needle));
        $this->assertCount(1, $requests, 'Expected exactly one Telegram message containing '.$needle.'.');

        return $requests->first()[0];
    }

    private function sentMessageRequestForChat(string $chatId): Request
    {
        $requests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === $chatId);
        $this->assertCount(1, $requests, 'Expected exactly one Telegram message for chat '.$chatId.'.');

        return $requests->first()[0];
    }

    private function callbackAnswerText(string $callbackId): string
    {
        $requests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && ($request['callback_query_id'] ?? null) === $callbackId);
        $this->assertCount(1, $requests, 'Expected exactly one callback answer for '.$callbackId.'.');

        return (string) ($requests->first()[0]['text'] ?? '');
    }

    private function paidCompletedOrderWithCredential(Customer $customer, string $invoiceNumber, string $productName, string $secret): Order
    {
        $product = Product::factory()->create(['name' => $productName, 'stock' => 1, 'reserved_stock' => 1, 'price_amount' => 25000]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => $invoiceNumber,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 25000,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $productName,
            'quantity' => 1,
            'subtotal' => 25000,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => $secret]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'method' => 'manual',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order;
    }

    private function requestBodyContainsAny(Request $request, array $needles): bool
    {
        $body = strtolower(json_encode($request->data()) ?: '');

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($body, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
