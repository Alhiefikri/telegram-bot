<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\PublishSalesProofPost;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\SalesProofPostFormatter;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class PaymentProofAdminCallbackTest extends TelegramBotTestSupport
{
    public function test_payment_photo_is_forwarded_to_admin_and_admin_approval_sends_receipt(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(211, 'cb-manual', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(212, $order->invoice_number, ['username' => 'buyer']))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['chat_id'] === '999'
            && $r['photo'] === 'large-photo'
            && str_contains((string) ($r['caption'] ?? ''), 'Bukti pembayaran masuk')
            && str_contains(json_encode($r->data()), 'admin:approve-payment:'.$order->id)
            && str_contains(json_encode($r->data()), 'admin:reject-payment:'.$order->id));

        $this->postTelegramWebhook($this->callbackUpdate(213, 'approve-payment', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains($r['text'], 'Pembayaran diterima')
            && str_contains($r['text'], 'Email Account'));

        $this->postTelegramWebhook($this->callbackUpdate(219, 'approve-payment-again', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $receiptCount = 0;
        Http::assertSent(function ($r) use (&$receiptCount) {
            if (str_ends_with($r->url(), '/sendMessage')
                && $r['chat_id'] === 12345
                && str_contains((string) ($r['text'] ?? ''), 'Pembayaran diterima')) {
                $receiptCount++;
            }

            return true;
        });
        $this->assertSame(1, $receiptCount);
    }

    public function test_manual_approval_publishes_one_privacy_safe_sales_proof_for_multi_product_order(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $this->fakeTelegramHttp(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 88]])]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'AdamSecret']);
        $first = Product::factory()->create(['name' => 'Email Account', 'stock' => 5, 'price_amount' => 12000]);
        $second = Product::factory()->create(['name' => 'Canva Pro', 'stock' => 5, 'price_amount' => 34000]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $first, 1);
        app(CartService::class)->addItem($cart, $second, 1);

        $this->postTelegramWebhook($this->callbackUpdate(301, 'cb-manual-proof', 'checkout'))->assertOk();
        $order = Order::firstOrFail();
        $this->postTelegramWebhook($this->paymentPhotoUpdate(302, $order->invoice_number, ['username' => 'AdamSecret']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(303, 'approve-proof', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();
        app()->call([new PublishSalesProofPost($order->id), 'handle']);
        $this->postTelegramWebhook($this->callbackUpdate(304, 'approve-proof-again', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(1, SalesProofPost::where('status', SalesProofPost::STATUS_SENT)->count());
        $copy = app(SalesProofPostFormatter::class)->format($order->fresh(['customer', 'orderItems']));
        $this->assertMatchesRegularExpression('/Buyer: [A-Za-z0-9_]{2}\*\*\*/', $copy);
        $this->assertStringContainsString('+ 1 produk lainnya', $copy);
        $this->assertStringContainsString('Total diproses: #1', $copy);
        $this->assertStringNotContainsString($order->invoice_number, $copy);
        $this->assertStringNotContainsString('12000', $copy);
        $this->assertStringNotContainsString('34000', $copy);
    }

    public function test_sales_proof_post_failure_is_recorded_and_can_retry_up_to_success(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'RetryBuyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Retry Product', 'unit_price' => 12000, 'subtotal' => 12000]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::sequence()
                ->push(['ok' => false, 'description' => 'temporary channel failure'])
                ->push(['ok' => true, 'result' => ['message_id' => 99]]),
        ]);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        $post = SalesProofPost::firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertSame(1, $post->attempts);
        $this->assertNotNull($post->last_error);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        $post = $post->fresh();
        $this->assertSame(SalesProofPost::STATUS_SENT, $post->status);
        $this->assertSame(2, $post->attempts);
        $this->assertSame('99', $post->telegram_message_id);
        $this->assertNull($post->last_error);
        $this->assertNotNull($post->sent_at);
    }

    public function test_paid_account_order_receipt_contains_only_the_buyers_allocated_secret(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $this->fakeTelegramHttp();

        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        Customer::factory()->create(['telegram_id' => 54321, 'username' => 'other']);
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'stock' => 0, 'reserved_stock' => 0]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'seed credential', null, null, [
            'email: buyer@example.test | password: Secret-123',
        ]);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product->fresh(), 1);
        $this->postTelegramWebhook($this->callbackUpdate(221, 'cb-checkout-secret', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->callbackUpdate(222, 'approve-secret', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains((string) $r['text'], 'Pembayaran diterima')
            && str_contains((string) $r['text'], 'email: buyer@example.test | password: Secret-123'));

        $this->postTelegramWebhook($this->messageUpdate(223, '/order '.$order->invoice_number, ['id' => 54321, 'username' => 'other'], 54321))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '54321'
            && str_contains((string) $r['text'], 'Pesanan tidak ditemukan'));
        Http::assertNotSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '54321'
            && str_contains((string) ($r['text'] ?? ''), 'Secret-123'));
        Http::assertNotSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '999'
            && str_contains((string) (($r['text'] ?? '').($r['caption'] ?? '')), 'Secret-123'));
    }

    public function test_admin_can_broadcast_message_from_bot_to_active_users(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 111]);
        Customer::factory()->create(['telegram_id' => 222]);

        $this->postTelegramWebhook($this->messageUpdate(224, '/broadcast Stok Canva ready malam ini.', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        foreach ([111, 222, 999] as $chatId) {
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
                && (string) $r['chat_id'] === (string) $chatId
                && str_contains((string) $r['text'], 'Stok Canva ready malam ini.'));
        }

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) $r['text'], 'Broadcast selesai. Terkirim: 3. Gagal: 0.'));
    }

    public function test_non_admin_cannot_approve_manual_payment_callback(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(214, 'cb-manual', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->callbackUpdate(215, 'fake-approve', 'admin:approve-payment:'.$order->id, ['id' => 555, 'first_name' => 'Mallory'], 555))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'fake-approve'
            && $r['text'] === 'Akses admin diperlukan.');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran diterima'));
    }

    public function test_admin_can_reject_manual_payment_callback_and_buyer_gets_human_message(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 2, 'reserved_stock' => 0]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'credential', null, null, ['buyer@example.test | pass']);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product->fresh(), 1);

        $this->postTelegramWebhook($this->callbackUpdate(231, 'cb-manual', 'payment:dana'))->assertOk();
        $order = Order::firstOrFail();
        $orderItem = $order->orderItems()->firstOrFail();
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame($orderItem->id, ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);

        $this->postTelegramWebhook($this->paymentPhotoUpdate(232, $order->invoice_number, ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(233, 'reject-payment', 'admin:reject-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.manual_rejected', 'subject_id' => (string) $order->id]);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'reject-payment'
            && $r['text'] === 'Pembayaran ditolak. Buyer sudah diberi info.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains($r['text'], 'Pembayaran belum bisa kami terima')
            && str_contains($r['text'], $order->invoice_number)
            && str_contains($r['text'], 'kirim ulang bukti')
            && str_contains($r['text'], '@Alhie02'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains((string) ($r['text'] ?? ''), 'buyer@example.test | pass'));
    }

    public function test_non_admin_cannot_reject_manual_payment_callback(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(234, 'cb-manual', 'payment:dana'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->callbackUpdate(235, 'fake-reject', 'admin:reject-payment:'.$order->id, ['id' => 555, 'first_name' => 'Mallory'], 555))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'fake-reject'
            && $r['text'] === 'Akses admin diperlukan.');
    }

    public function test_customer_cannot_submit_payment_proof_for_another_customers_invoice(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $owner = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $attacker = Customer::factory()->create(['telegram_id' => 54321, 'username' => 'mallory']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($owner), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(216, 'cb-manual-owner', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(217, $order->invoice_number, ['id' => $attacker->telegram_id, 'username' => 'mallory'], 54321))->assertOk();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['chat_id'] === '999'
            && str_contains((string) ($r['caption'] ?? ''), $order->invoice_number));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '54321'
            && str_contains($r['text'], 'Invoice tidak ditemukan'));
    }

    public function test_receipt_notifier_sends_paid_order_receipt(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 777 && str_contains($r['text'], 'Pembayaran diterima'));
    }

    public function test_pakasir_paid_callback_sends_telegram_receipt_once_on_duplicate_callback(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 888]);
        $product = Product::factory()->create(['stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'hosted', 'pakasir');
        $payment = $order->payments()->first();
        $payload = ['project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref, 'amount' => $payment->amount, 'status' => 'completed'];
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload]),
        ]);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
        $this->postJson('/api/webhook/pakasir', $payload)->assertUnprocessable();

        $sent = 0;
        Http::assertSent(function ($r) use (&$sent) {
            if (str_ends_with($r->url(), '/sendMessage') && ($r['chat_id'] ?? null) === 888) {
                $sent++;
            }

            return true;
        });
        $this->assertSame(1, $sent);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }
}
