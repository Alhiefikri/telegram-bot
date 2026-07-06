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
use App\Services\OrderCheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $old = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OLD', 'created_at' => now()->subDay()]);
        $new = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-NEW', 'payment_status' => PaymentStatus::Paid, 'created_at' => now()]);

        $this->postTelegramWebhook($this->messageUpdate(201, '/riwayat'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat Pesanan')
            && strpos($r['text'], $new->invoice_number) < strpos($r['text'], $old->invoice_number)
            && str_contains($r['text'], 'paid')
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
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-DETAIL', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Canva Pro', 'quantity' => 2, 'subtotal' => 20000]);
        Payment::factory()->create(['order_id' => $order->id, 'method' => 'hosted', 'provider' => 'pakasir', 'status' => PaymentStatus::Pending]);

        $this->postTelegramWebhook($this->messageUpdate(205, '/order INV-DETAIL'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Canva Pro x 2')
            && str_contains($r['text'], 'Pembayaran: QRIS (pending)')
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
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
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
            && str_contains($r['text'], 'Pembayaran: QRIS (pending)')
            && ! str_contains($r['text'], 'Pembayaran: hosted')
            && ! str_contains($r['text'], 'Pakasir'));
    }

    public function test_order_detail_refreshes_pending_pakasir_payment_from_transaction_detail(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'price_amount' => 10000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'qris', 'pakasir');
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
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
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
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'qris', 'pakasir');
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
        config()->set('app.timezone', 'Asia/Singapore');
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
