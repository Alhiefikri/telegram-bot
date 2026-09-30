<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ReconcileCustomerCancellationPayment;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramNavigationRoot;
use App\Services\CartService;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class OrderStatusHistoryTest extends TelegramBotTestSupport
{
    public function test_batal_abandons_cart_and_status_reports_latest_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        app(CartService::class)->activeCartFor($customer);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $this->postTelegramWebhook($this->messageUpdate(115, '/batal'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(116, '/status'))->assertOk();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id, 'status' => 'abandoned']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], $order->invoice_number));
    }

    public function test_status_history_button_opens_the_recent_order_screen(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-STATUS-HISTORY',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(117, '/status'))->assertOk();
        $status = $this->sentMessageRequestContaining($order->invoice_number);
        $historyCallback = $status['reply_markup']['inline_keyboard'][0][0]['callback_data'];

        $this->postTelegramWebhook($this->callbackUpdate(
            118,
            'cb-status-history',
            $historyCallback,
        ))->assertOk();

        $this->assertDatabaseHas('telegram_navigation_roots', [
            'customer_id' => $customer->id,
            'screen' => 'orders',
        ]);
        $history = $this->sentMessageRequestContaining('Pesanan Berhasil');
        $this->assertSame('📦 Pesanan Berhasil', $history['text']);
        $this->assertSame('order:'.$order->invoice_number, $history['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringNotContainsString($order->invoice_number, (string) $history['text']);
    }

    public function test_riwayat_lists_latest_successful_orders_as_compact_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $old = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-OLD',
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Failed,
            'created_at' => now()->subDay(),
        ]);
        $new = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-NEW',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now(),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(201, '/riwayat'))->assertOk();

        $request = $this->sentMessageRequestContaining('Pesanan Berhasil');
        $this->assertSame('📦 Pesanan Berhasil', $request['text']);
        $this->assertStringNotContainsString($new->invoice_number, (string) $request['text']);
        $this->assertStringNotContainsString($old->invoice_number, (string) $request['text']);

        $keyboard = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('order:'.$new->invoice_number, $keyboard);
        $this->assertStringContainsString('Terkirim', $keyboard);
        $this->assertStringNotContainsString('order:'.$old->invoice_number, $keyboard);
    }

    public function test_riwayat_paginates_successful_orders_with_stable_compact_buttons(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);

        for ($index = 1; $index <= 11; $index++) {
            Order::factory()->create([
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-PAGE-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'created_at' => now()->subMinutes(11 - $index),
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(206, '/riwayat'))->assertOk();

        $request = $this->sentMessageRequestContaining('Pesanan Berhasil');
        $keyboard = $request['reply_markup']['inline_keyboard'];
        $orderCallbacks = array_values(array_filter(
            array_map(fn (array $row) => $row[0]['callback_data'] ?? null, $keyboard),
            fn ($callback): bool => is_string($callback) && str_starts_with($callback, 'order:'),
        ));
        $expectedCallbacks = [];
        for ($index = 11; $index >= 2; $index--) {
            $expectedCallbacks[] = 'order:INV-PAGE-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }
        $this->assertSame($expectedCallbacks, $orderCallbacks);
        $this->assertSame('📦 Pesanan Berhasil', $request['text']);
        $this->assertStringNotContainsString('INV-PAGE-01', (string) $request['text']);
        $this->assertMatchesRegularExpression(
            '/\Aorders:older:[0-9]{14}:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/',
            $keyboard[10][0]['callback_data'],
        );
        $this->assertLessThanOrEqual(64, strlen($keyboard[10][0]['callback_data']));

        Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-PAGE-12',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => now()->addMinute(),
        ]);

        $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->postTelegramWebhook($this->callbackUpdate(
            207,
            'cb-page-2',
            $keyboard[10][0]['callback_data'],
            [],
            12345,
            'private',
            $root->message_id,
        ))->assertOk();

        $pageTwo = Http::recorded(fn (Request $recorded): bool => str_ends_with($recorded->url(), '/editMessageText'))
            ->last()[0];
        $this->assertSame('📦 Pesanan Berhasil', $pageTwo['text']);
        $pageTwoKeyboard = $pageTwo['reply_markup']['inline_keyboard'];
        $pageTwoCallbacks = array_values(array_filter(
            array_map(fn (array $row) => $row[0]['callback_data'] ?? null, $pageTwoKeyboard),
            fn ($callback): bool => is_string($callback) && str_starts_with($callback, 'order:'),
        ));
        $this->assertSame(['order:INV-PAGE-01'], $pageTwoCallbacks);
        $this->assertStringNotContainsString('order:INV-PAGE-02', json_encode($pageTwoKeyboard, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('order:INV-PAGE-12', json_encode($pageTwoKeyboard, JSON_THROW_ON_ERROR));
    }

    public function test_riwayat_fallback_when_no_successful_orders(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->postTelegramWebhook($this->messageUpdate(202, '/riwayat'))->assertOk();

        $request = $this->sentMessageRequestContaining('Belum ada pesanan berhasil.');
        $this->assertSame('📦 Belum ada pesanan berhasil.', $request['text']);
        $this->assertSame('🛍️ Lihat Produk', $request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
    }

    #[DataProvider('emptyOrderLocaleCases')]
    public function test_empty_status_and_history_use_customer_locale(array $copy): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);

        $this->postTelegramWebhook($this->messageUpdate(220, '/status'))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(221, $copy['historyCommand']))->assertOk();

        $requests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345');
        $this->assertCount(2, $requests);
        $statusText = (string) $requests->first()[0]['text'];
        $historyRequest = $requests->last()[0];
        $historyText = (string) $historyRequest['text'];
        $this->assertStringContainsString($copy['status'], $statusText);
        $this->assertStringContainsString($copy['history'], $historyText);
        $this->assertSame($copy['browse'], $historyRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringNotContainsString($copy['otherStatus'], $statusText);
        $this->assertStringNotContainsString($copy['otherHistory'], $historyText);
    }

    public function test_riwayat_hides_other_customer_orders(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $own = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-OWN',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $other = Order::factory()->create([
            'invoice_number' => 'INV-OTHER',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(203, '/riwayat'))->assertOk();

        $request = $this->sentMessageRequestContaining('Pesanan Berhasil');
        $keyboard = json_encode($request['reply_markup'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('order:'.$own->invoice_number, $keyboard);
        $this->assertStringNotContainsString('order:'.$other->invoice_number, $keyboard);
    }

    public function test_order_without_invoice_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->postTelegramWebhook($this->messageUpdate(204, '/order'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik nomor invoice setelah command.')
            && ! str_contains($r['text'], 'command ya')
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
            && str_contains($r['text'], '🛍️ Canva Pro x 2')
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
        $customer = Customer::factory()->create(['telegram_id' => $privateChatId, 'locale' => 'id']);
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
        $customer = Customer::factory()->create(['telegram_id' => $privateChatId, 'locale' => 'id']);
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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = $this->legacyPakasirOrder($customer->carts()->first());
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

    #[DataProvider('manualPaymentLocaleCases')]
    public function test_order_detail_localizes_qr_manual_payment_method(string $locale, int $chatId, string $label): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => $chatId, 'locale' => $locale]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-MANUAL-'.$locale,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Manual QR Product', 'quantity' => 1, 'subtotal' => 10000]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Pending,
        ]);

        $this->postTelegramWebhook($this->messageUpdate($chatId + 1000, '/order '.$order->invoice_number, ['id' => $chatId], $chatId))->assertOk();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === (string) $chatId
            && str_contains((string) ($request['text'] ?? ''), $label)
            && ! str_contains((string) ($request['text'] ?? ''), 'QR_MANUAL'));
    }

    public static function manualPaymentLocaleCases(): array
    {
        return [
            'indonesian' => ['id', 12351, 'QR Manual'],
            'english' => ['en', 12352, 'Manual QR'],
            'malay' => ['ms', 12353, 'QR Manual'],
        ];
    }

    public function test_order_detail_status_refresh_is_rate_limited_per_payment(): void
    {
        config()->set('services.pakasir.status_refresh_rate_limit_per_minute', 1);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = $this->legacyPakasirOrder($customer->carts()->first());
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
            && str_contains($r['text'], 'Baru bisa cek status pembayaran lagi'));
    }

    public function test_order_cannot_access_other_customer_order(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(206, '/order INV-SECRET'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-SECRET'));
    }

    public function test_order_callback_valid_detail_and_manipulated_callback_cannot_leak_other_order(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
            $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
    public function test_history_alias_localizes_compact_buttons(array $copy): void
    {
        config()->set('app.timezone', 'Asia/Jakarta');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-HISTORY-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'created_at' => CarbonImmutable::parse('2026-05-04 15:30:00', 'Asia/Jakarta'),
        ]);

        $this->postTelegramWebhook($this->messageUpdate(302, $copy['historyCommand']))->assertOk();

        $request = $this->sentMessageRequestContaining($copy['historyTitle']);
        $text = (string) $request['text'];
        $button = $request['reply_markup']['inline_keyboard'][0][0] ?? [];
        $this->assertStringContainsString($copy['historyTitle'], $text);
        $this->assertSame('📦 '.$order->invoice_number.' · '.$copy['historyDelivered'], $button['text'] ?? null);
        $this->assertSame('order:'.$order->invoice_number, $button['callback_data'] ?? null);
        $this->assertStringNotContainsString($order->invoice_number, $text);
        $this->assertStringNotContainsString($copy['otherHistoryTitle'], $text);
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

    #[DataProvider('orderGuidanceLocaleCases')]
    public function test_order_guidance_states_use_customer_locale(array $case): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 12345,
            'locale' => $case['locale'],
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-GUIDANCE-'.$case['locale'].'-'.$case['state'],
            'order_status' => $case['orderStatus'],
            'payment_status' => $case['paymentStatus'],
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'manual',
            'status' => $case['paymentStatus'],
            'paid_at' => $case['paymentStatus'] === PaymentStatus::Paid ? now() : null,
        ]);
        if ($case['manualReview']) {
            ManualFulfillmentReview::create([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
                'state' => ManualFulfillmentReview::STATE_PENDING,
            ]);
        }

        $this->postTelegramWebhook($this->messageUpdate(305, '/order '.$order->invoice_number))->assertOk();

        $text = $this->sentMessageContaining($order->invoice_number);
        $this->assertStringContainsString($case['guidance'], $text);
        $this->assertStringNotContainsString($case['otherGuidance'], $text);
    }

    #[DataProvider('storeBalanceGuidanceLocaleCases')]
    public function test_store_balance_guidance_uses_localized_refund_and_delivery_copy(array $case): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create([
            'telegram_id' => 12345,
            'locale' => $case['locale'],
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-BALANCE-GUIDANCE-'.$case['locale'].'-'.$case['state'],
            'order_status' => $case['orderStatus'],
            'payment_status' => $case['paymentStatus'],
            'total_amount' => 15_000,
            'currency' => 'IDR',
            'telegram_receipt_sent_at' => null,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'store_balance',
            'method' => 'STORE_BALANCE',
            'status' => $case['paymentStatus'],
            'amount' => 15_000,
            'currency' => 'IDR',
        ]);

        $this->postTelegramWebhook($this->messageUpdate(306, '/order '.$order->invoice_number))->assertOk();

        $text = $this->sentMessageContaining($order->invoice_number);
        $this->assertStringContainsString($case['guidance'], $text);
        $this->assertStringNotContainsString($case['otherGuidance'], $text);
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
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactioncancel' => Http::response([
                'transaction' => ['status' => 'cancelled'],
            ]),
        ]);
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
        $this->assertStringNotContainsString('━━━━━━━━━━━━━━', $message);
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

    #[DataProvider('cancellationFailureLocaleCases')]
    public function test_buyer_cancel_verification_failure_is_localized(array $copy): void
    {
        Queue::fake([ReconcileCustomerCancellationPayment::class]);
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => function () use (&$payment) {
                return Http::response(['transaction' => [
                    'project' => 'unexpected-project',
                    'amount' => $payment->amount,
                    'order_id' => $payment->merchant_ref,
                    'payment_method' => 'qris',
                    'status' => 'pending',
                ]]);
            },
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CANCEL-VERIFY-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'provider_payment_number_hash' => hash('sha256', 'verify-'.$copy['locale']),
            'provider_created_at' => now(),
        ]);

        $callbackId = 'cancel-verify-'.$copy['locale'];
        $this->postTelegramWebhook($this->callbackUpdate(310, $callbackId, 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame($copy['verificationAnswer'], $this->callbackAnswerText($callbackId));
        $message = $this->sentMessageRequestContaining($copy['verificationDetail']);
        $this->assertStringNotContainsString($copy['otherVerificationDetail'], (string) $message['text']);
    }

    #[DataProvider('cancellationFailureLocaleCases')]
    public function test_buyer_cancel_paid_provider_outcome_is_localized(array $copy): void
    {
        $payment = null;
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => function () use (&$payment) {
                return Http::response(['transaction' => [
                    'project' => 'sedulur-digital',
                    'amount' => $payment->amount,
                    'order_id' => $payment->merchant_ref,
                    'payment_method' => 'qris',
                    'status' => 'completed',
                ]]);
            },
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CANCEL-PAID-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'method' => 'api_qris',
            'provider_payment_method' => 'qris',
            'provider_payment_number_hash' => hash('sha256', 'paid-'.$copy['locale']),
            'provider_created_at' => now(),
        ]);

        $callbackId = 'cancel-paid-'.$copy['locale'];
        $this->postTelegramWebhook($this->callbackUpdate(311, $callbackId, 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame($copy['paidAnswer'], $this->callbackAnswerText($callbackId));
        $message = $this->sentMessageRequestContaining($copy['paidDetail']);
        $this->assertStringNotContainsString($copy['otherPaidDetail'], (string) $message['text']);
    }

    #[DataProvider('cancellationFailureLocaleCases')]
    public function test_buyer_cancel_processed_outcome_is_localized(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CANCEL-PROCESSED-'.strtoupper($copy['locale']),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'manual',
            'status' => PaymentStatus::Pending,
        ]);
        $lifecycle = $this->mock(OrderLifecycle::class);
        $lifecycle->shouldReceive('applyPendingCancellation')
            ->once()
            ->andReturn(new OrderLifecycleResult($order, $payment, false));
        $this->app->instance(OrderLifecycle::class, $lifecycle);

        $callbackId = 'cancel-processed-'.$copy['locale'];
        $this->postTelegramWebhook($this->callbackUpdate(312, $callbackId, 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame($copy['processedAnswer'], $this->callbackAnswerText($callbackId));
        $message = $this->sentMessageRequestContaining($copy['processedDetail']);
        $this->assertStringNotContainsString($copy['otherProcessedDetail'], (string) $message['text']);
    }

    public function test_malay_buyer_cancellation_messages_cover_success_and_stale_states(): void
    {
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactioncancel' => Http::response([
                'transaction' => ['status' => 'cancelled'],
            ]),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'ms']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CANCEL-MS',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->callbackUpdate(308, 'cancel-success-ms', 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame('Pesanan dibatalkan dan stok telah dilepaskan.', $this->callbackAnswerText('cancel-success-ms'));
        $this->assertStringContainsString('Pesanan dibatalkan', $this->sentMessageContaining($order->invoice_number));

        $this->postTelegramWebhook($this->callbackUpdate(309, 'cancel-stale-ms', 'buyer:cancel-pending:'.$order->id))->assertOk();

        $this->assertSame('Pesanan ini tidak lagi boleh dibatalkan.', $this->callbackAnswerText('cancel-stale-ms'));
        $messages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345')
            ->map(fn (array $record): string => (string) ($record[0]['text'] ?? ''));
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Pesanan tidak ditemui atau tidak lagi boleh dibatalkan.', $messages->last());
        $this->assertStringNotContainsString('Order not found or can no longer be cancelled.', $messages->last());
    }

    public static function emptyOrderLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'historyCommand' => '/riwayat',
                'status' => 'Belum ada pesanan nih',
                'otherStatus' => 'You do not have any orders yet',
                'history' => 'Belum ada pesanan berhasil.',
                'otherHistory' => 'No successful orders yet.',
                'browse' => '🛍️ Lihat Produk',
            ]],
            'English' => [[
                'locale' => 'en',
                'historyCommand' => '/history',
                'status' => 'You do not have any orders yet',
                'otherStatus' => 'Belum ada pesanan nih',
                'history' => 'No successful orders yet.',
                'otherHistory' => 'Belum ada pesanan berhasil.',
                'browse' => '🛍️ Browse Products',
            ]],
            'Malay' => [[
                'locale' => 'ms',
                'historyCommand' => '/sejarah',
                'status' => 'Tiada pesanan lagi',
                'otherStatus' => 'You do not have any orders yet',
                'history' => 'Tiada pesanan berjaya lagi.',
                'otherHistory' => 'No successful orders yet.',
                'browse' => '🛍️ Lihat Produk',
            ]],
        ];
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
                'historyTitle' => 'Pesanan Berhasil',
                'otherHistoryTitle' => 'Successful Orders',
                'historyDelivered' => 'Terkirim',
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
                'historyTitle' => 'Successful Orders',
                'otherHistoryTitle' => 'Pesanan Berhasil',
                'historyDelivered' => 'Delivered',
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
            'Malay' => [[
                'locale' => 'ms',
                'historyCommand' => '/sejarah',
                'statusTitle' => 'Status pesanan',
                'otherStatusTitle' => 'Order status',
                'orderStatusLabel' => 'Pesanan:',
                'paymentStatusLabel' => 'Bayaran:',
                'orderPending' => 'Menunggu bayaran',
                'paymentPending' => 'Menunggu bayaran',
                'historyTitle' => 'Pesanan Berjaya',
                'otherHistoryTitle' => 'Successful Orders',
                'historyDelivered' => 'Dihantar',
                'orderTitle' => 'Butiran Pesanan',
                'otherOrderTitle' => 'Order Details',
                'orderCompleted' => 'Selesai',
                'paymentPaid' => 'Dibayar',
                'createdLabel' => 'Dibuat:',
                'createdDate' => '04 Mei 2026 15:30 WIB',
                'paidLabel' => 'Dibayar:',
                'paidDate' => '05 Mei 2026 09:15 WIB',
                'paymentLabel' => 'Bayaran:',
                'deliveryNoteHeading' => '📌 Nota penghantaran:',
                'otherDeliveryNoteHeading' => '📌 Delivery note:',
                'privacyMessage' => 'Teruskan di chat peribadi',
                'otherPrivacyMessage' => 'Continue in a private chat',
                'privacyCallback' => 'teruskan di chat peribadi',
                'otherPrivacyCallback' => 'continue in a private chat',
                'cancelSuccessAnswer' => 'Pesanan dibatalkan dan stok telah dilepaskan.',
                'cancelSuccessMessage' => 'Pesanan dibatalkan',
                'otherCancelSuccessMessage' => 'Order cancelled',
                'cancelStaleAnswer' => 'Pesanan ini tidak lagi boleh dibatalkan.',
                'cancelStaleMessage' => 'Pesanan tidak ditemui atau tidak lagi boleh dibatalkan.',
                'otherCancelStaleMessage' => 'Order not found or can no longer be cancelled.',
            ]],
        ];
    }

    public static function orderGuidanceLocaleCases(): array
    {
        $states = [
            'pending' => [OrderStatus::PendingPayment, PaymentStatus::Pending, false],
            'processing' => [OrderStatus::Processing, PaymentStatus::Paid, false],
            'completed' => [OrderStatus::Completed, PaymentStatus::Paid, false],
            'cancelled' => [OrderStatus::Cancelled, PaymentStatus::Pending, false],
            'expired' => [OrderStatus::Expired, PaymentStatus::Expired, false],
            'payment_failed' => [OrderStatus::Cancelled, PaymentStatus::Failed, false],
            'refunded' => [OrderStatus::Completed, PaymentStatus::Refunded, false],
            'late_payment' => [OrderStatus::Expired, PaymentStatus::Paid, false],
            'late_payment_cancelled' => [OrderStatus::Cancelled, PaymentStatus::Paid, false],
            'manual_review_cancelled' => [OrderStatus::Cancelled, PaymentStatus::Paid, true],
            'manual_review' => [OrderStatus::Expired, PaymentStatus::Paid, true],
        ];
        $guidance = [
            'id' => [
                'pending' => ['Order masih menunggu pembayaran.', 'This order is awaiting payment.'],
                'processing' => ['Pembayaran sudah diterima. Pesanan sedang diproses.', 'Payment has been received.'],
                'completed' => ['Pembayaran sudah diterima. Pesanan sudah selesai diproses.', 'Payment has been received. Your order is complete.'],
                'cancelled' => ['Order ini sudah dibatalkan.', 'This order was cancelled.'],
                'expired' => ['Order ini expired sebelum pembayaran selesai.', 'This order expired before payment was completed.'],
                'payment_failed' => ['Pembayaran gagal atau ditolak', 'Payment failed or was rejected'],
                'refunded' => ['Pembayaran ini sudah dikembalikan.', 'This payment was refunded.'],
                'late_payment' => ['Pembayaran diterima setelah order expired.', 'Payment was received after this order expired.'],
                'late_payment_cancelled' => ['Pembayaran diterima setelah order dibatalkan.', 'Payment was received after this order was cancelled.'],
                'manual_review_cancelled' => ['Pembayaran diterima setelah order dibatalkan dan sedang menunggu review manual.', 'Payment was received after this order was cancelled and is waiting for manual review.'],
                'manual_review' => ['sedang menunggu review manual.', 'waiting for manual review.'],
            ],
            'en' => [
                'pending' => ['This order is awaiting payment.', 'Order masih menunggu pembayaran.'],
                'processing' => ['Payment has been received. Your order is being processed.', 'Pembayaran sudah diterima.'],
                'completed' => ['Payment has been received. Your order is complete.', 'Pembayaran sudah diterima.'],
                'cancelled' => ['This order was cancelled.', 'Order ini sudah dibatalkan.'],
                'expired' => ['This order expired before payment was completed.', 'Order ini expired sebelum pembayaran selesai.'],
                'payment_failed' => ['Payment failed or was rejected', 'Pembayaran gagal atau ditolak'],
                'refunded' => ['This payment was refunded.', 'Pembayaran ini sudah dikembalikan.'],
                'late_payment' => ['Payment was received after this order expired.', 'Pembayaran diterima setelah order expired.'],
                'late_payment_cancelled' => ['Payment was received after this order was cancelled.', 'Pembayaran diterima setelah order dibatalkan.'],
                'manual_review_cancelled' => ['Payment was received after this order was cancelled and is waiting for manual review.', 'Pembayaran diterima setelah order dibatalkan dan sedang menunggu review manual.'],
                'manual_review' => ['waiting for manual review.', 'sedang menunggu review manual.'],
            ],
            'ms' => [
                'pending' => ['Pesanan ini sedang menunggu bayaran.', 'This order is awaiting payment.'],
                'processing' => ['Bayaran telah diterima. Pesanan anda sedang diproses.', 'Pembayaran sudah diterima.'],
                'completed' => ['Bayaran telah diterima. Pesanan anda telah selesai.', 'Payment has been received.'],
                'cancelled' => ['Pesanan ini telah dibatalkan.', 'This order was cancelled.'],
                'expired' => ['Pesanan ini tamat tempoh sebelum bayaran selesai.', 'This order expired before payment was completed.'],
                'payment_failed' => ['Bayaran gagal atau ditolak', 'Payment failed or was rejected'],
                'refunded' => ['Bayaran ini telah dipulangkan.', 'This payment was refunded.'],
                'late_payment' => ['Bayaran diterima selepas pesanan tamat tempoh.', 'Payment was received after this order expired.'],
                'late_payment_cancelled' => ['Bayaran diterima selepas pesanan dibatalkan.', 'Payment was received after this order was cancelled.'],
                'manual_review_cancelled' => ['Bayaran diterima selepas pesanan dibatalkan dan sedang menunggu semakan manual.', 'Payment was received after this order was cancelled and is waiting for manual review.'],
                'manual_review' => ['sedang menunggu semakan manual.', 'waiting for manual review.'],
            ],
        ];
        $cases = [];
        foreach ($guidance as $locale => $localeGuidance) {
            foreach ($states as $state => [$orderStatus, $paymentStatus, $manualReview]) {
                $cases[$locale.'-'.$state] = [[
                    'locale' => $locale,
                    'state' => $state,
                    'orderStatus' => $orderStatus,
                    'paymentStatus' => $paymentStatus,
                    'manualReview' => $manualReview,
                    'guidance' => $localeGuidance[$state][0],
                    'otherGuidance' => $localeGuidance[$state][1],
                ]];
            }
        }

        return $cases;
    }

    public static function storeBalanceGuidanceLocaleCases(): array
    {
        return [
            'id-refunded' => [[
                'locale' => 'id',
                'state' => 'refunded',
                'orderStatus' => OrderStatus::Completed,
                'paymentStatus' => PaymentStatus::Refunded,
                'guidance' => 'Pembayaran dengan Saldo sudah dikembalikan. Rp 15.000 sudah dikembalikan ke Saldo kamu.',
                'otherGuidance' => 'Your Store Balance payment was refunded.',
            ]],
            'en-refunded' => [[
                'locale' => 'en',
                'state' => 'refunded',
                'orderStatus' => OrderStatus::Completed,
                'paymentStatus' => PaymentStatus::Refunded,
                'guidance' => 'Your Store Balance payment was refunded. Rp 15.000 was restored to your Balance.',
                'otherGuidance' => 'Pembayaran dengan Saldo sudah dikembalikan.',
            ]],
            'ms-refunded' => [[
                'locale' => 'ms',
                'state' => 'refunded',
                'orderStatus' => OrderStatus::Completed,
                'paymentStatus' => PaymentStatus::Refunded,
                'guidance' => 'Bayaran Baki anda telah dipulangkan. Rp 15.000 telah dikembalikan ke Baki anda.',
                'otherGuidance' => 'Your Store Balance payment was refunded.',
            ]],
            'id-pending-delivery' => [[
                'locale' => 'id',
                'state' => 'pending-delivery',
                'orderStatus' => OrderStatus::Processing,
                'paymentStatus' => PaymentStatus::Paid,
                'guidance' => 'Pembayaran dengan Saldo berhasil. Bukti pesanan masih sedang dikirim; coba cek Pesanan lagi sebentar.',
                'otherGuidance' => 'Your Store Balance payment succeeded.',
            ]],
            'en-pending-delivery' => [[
                'locale' => 'en',
                'state' => 'pending-delivery',
                'orderStatus' => OrderStatus::Processing,
                'paymentStatus' => PaymentStatus::Paid,
                'guidance' => 'Your Store Balance payment succeeded. Your order receipt is still being delivered; check Orders again shortly.',
                'otherGuidance' => 'Pembayaran dengan Saldo berhasil.',
            ]],
            'ms-pending-delivery' => [[
                'locale' => 'ms',
                'state' => 'pending-delivery',
                'orderStatus' => OrderStatus::Processing,
                'paymentStatus' => PaymentStatus::Paid,
                'guidance' => 'Bayaran melalui Baki berjaya. Resit pesanan anda masih sedang dihantar; semak Pesanan semula sebentar lagi.',
                'otherGuidance' => 'Your Store Balance payment succeeded.',
            ]],
        ];
    }

    public static function cancellationFailureLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'verificationAnswer' => 'Status pembayaran belum dapat diverifikasi.',
                'verificationDetail' => 'Status pembayaran belum dapat diverifikasi. Pesanan tidak dibatalkan; coba lagi sebentar.',
                'otherVerificationDetail' => 'Payment status cannot be verified yet.',
                'paidAnswer' => 'Pembayaran sudah diterima.',
                'paidDetail' => 'Pembayaran sudah diterima, jadi pesanan tidak bisa dibatalkan.',
                'otherPaidDetail' => 'Payment has already been received.',
                'processedAnswer' => 'Pesanan sudah tidak bisa dibatalkan.',
                'processedDetail' => 'Pesanan sudah diproses atau tidak bisa dibatalkan lagi.',
                'otherProcessedDetail' => 'This order can no longer be cancelled.',
            ]],
            'English' => [[
                'locale' => 'en',
                'verificationAnswer' => 'Payment status cannot be verified yet.',
                'verificationDetail' => 'Payment status cannot be verified yet. Your order was not cancelled; please try again shortly.',
                'otherVerificationDetail' => 'Status pembayaran belum dapat diverifikasi.',
                'paidAnswer' => 'Payment has already been received.',
                'paidDetail' => 'Payment has already been received, so this order cannot be cancelled.',
                'otherPaidDetail' => 'Pembayaran sudah diterima.',
                'processedAnswer' => 'This order can no longer be cancelled.',
                'processedDetail' => 'This order has been processed or can no longer be cancelled.',
                'otherProcessedDetail' => 'Pesanan sudah tidak bisa dibatalkan.',
            ]],
            'Malay' => [[
                'locale' => 'ms',
                'verificationAnswer' => 'Status bayaran belum dapat disahkan.',
                'verificationDetail' => 'Status bayaran belum dapat disahkan. Pesanan anda tidak dibatalkan; sila cuba lagi sebentar lagi.',
                'otherVerificationDetail' => 'Payment status cannot be verified yet.',
                'paidAnswer' => 'Bayaran telah diterima.',
                'paidDetail' => 'Bayaran telah diterima, jadi pesanan ini tidak boleh dibatalkan.',
                'otherPaidDetail' => 'Payment has already been received.',
                'processedAnswer' => 'Pesanan ini tidak lagi boleh dibatalkan.',
                'processedDetail' => 'Pesanan ini telah diproses atau tidak lagi boleh dibatalkan.',
                'otherProcessedDetail' => 'This order can no longer be cancelled.',
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
