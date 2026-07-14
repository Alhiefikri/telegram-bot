<?php

namespace Tests\Feature\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\PublishSalesProofPost;
use App\Models\Customer;
use App\Models\InventoryMovement;
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
    public function test_english_customer_is_told_when_no_manual_order_is_waiting_for_payment_proof(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);

        $this->postTelegramWebhook($this->messageUpdate(201, '/payment'))->assertOk();

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345');
        $this->assertCount(1, $messages);
        $text = (string) ($messages->first()[0]['text'] ?? '');
        $this->assertStringContainsString('No manual order is awaiting payment', $text);
        $this->assertStringContainsString('send a payment screenshot as a PHOTO', $text);
        $this->assertStringNotContainsString('Belum ada pesanan manual', $text);
        $this->assertStringNotContainsString('kirim screenshot bukti transfer', $text);
    }

    public function test_english_customer_is_told_payment_proof_must_be_a_photo(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BRIVA',
            'status' => PaymentStatus::Pending,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(202, '/payment '.$order->invoice_number))->assertOk();

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345');
        $this->assertCount(1, $messages);
        $text = (string) ($messages->first()[0]['text'] ?? '');
        $this->assertStringContainsString('Payment proof must be sent as a PHOTO', $text);
        $this->assertStringContainsString('Upload the transfer screenshot', $text);
        $this->assertStringNotContainsString('Bukti pembayaran harus dikirim', $text);
        $this->assertStringNotContainsString('Isi caption dengan', $text);
    }

    public function test_english_customer_gets_localized_payment_proof_received_and_duplicate_responses(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'en']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BRIVA',
            'status' => PaymentStatus::Pending,
        ]);

        $this->postTelegramWebhook($this->paymentPhotoUpdate(203, $order->invoice_number, ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->paymentPhotoUpdate(204, $order->invoice_number, ['username' => 'buyer']))->assertOk();

        $texts = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345')
            ->map(fn (array $record): string => (string) ($record[0]['text'] ?? ''));
        $this->assertCount(2, $texts);
        $this->assertSame([
            'accepted' => true,
            'duplicate' => true,
        ], [
            'accepted' => $texts->contains(fn (string $text): bool => str_contains($text, 'Payment proof received')
                && str_contains($text, 'sent to the admin')),
            'duplicate' => $texts->contains(fn (string $text): bool => str_contains($text, 'Payment proof has already been received')
                && str_contains($text, 'awaiting admin verification')),
        ]);
        $customerCopy = $texts->implode("\n");
        $this->assertStringNotContainsString('Bukti pembayaran sudah masuk', $customerCopy);
        $this->assertStringNotContainsString('Bukti pembayaran sudah diterima', $customerCopy);
        $this->assertStringNotContainsString('menunggu verifikasi admin', $customerCopy);
    }

    public function test_english_paid_account_receipt_localizes_delivery_note_label_and_contains_only_the_buyers_secret(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $this->fakeTelegramHttp();

        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 54321, 'username' => 'other', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'delivery_note' => 'Rotate the password and MFA after signing in.', 'stock' => 0, 'reserved_stock' => 0]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'seed credential', null, null, [
            'email: buyer@example.test | password: Secret-123',
        ]);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product->fresh(), 1);
        $this->postTelegramWebhook($this->callbackUpdate(221, 'cb-checkout-secret', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->callbackUpdate(222, 'approve-secret', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $receiptMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && str_contains((string) ($request['text'] ?? ''), 'Secret-123'));
        $this->assertCount(1, $receiptMessages);
        $receiptRequest = $receiptMessages->first()[0];
        $receiptText = (string) ($receiptRequest['text'] ?? '');
        $this->assertStringContainsString('Payment received', $receiptText);
        $this->assertStringContainsString('Account details', $receiptText);
        $this->assertStringContainsString('email: buyer@example.test | password: Secret-123', $receiptText);
        $this->assertStringContainsString('Delivery note:', $receiptText);
        $this->assertStringContainsString('Rotate the password and MFA after signing in.', $receiptText);
        $this->assertStringContainsString('Need help signing in and securing the account?', $receiptText);
        $this->assertStringContainsString(route('storefront.login-guide'), $receiptText);
        $this->assertSame('Login & Security Guide', $receiptRequest['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertSame(route('storefront.login-guide'), $receiptRequest['reply_markup']['inline_keyboard'][0][0]['url'] ?? null);
        $visibleReceipt = json_encode($receiptRequest->data(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Pembayaran diterima', $visibleReceipt);
        $this->assertStringNotContainsString('Data akun', $visibleReceipt);
        $this->assertStringNotContainsString('Catatan pengiriman', $visibleReceipt);
        $this->assertStringNotContainsString('Bingung cara masuk', $visibleReceipt);
        $this->assertStringNotContainsString('Panduan Login & Keamanan', $visibleReceipt);

        $this->postTelegramWebhook($this->messageUpdate(223, '/order '.$order->invoice_number, ['id' => 54321, 'username' => 'other'], 54321))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '54321'
            && str_contains((string) $r['text'], 'Pesanan tidak ditemukan'));
        Http::assertNotSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '54321'
            && str_contains((string) ($r['text'] ?? ''), 'Secret-123'));
        Http::assertNotSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '999'
            && str_contains((string) (($r['text'] ?? '').($r['caption'] ?? '')), 'Secret-123'));
    }

    public function test_admin_can_reject_manual_payment_callback_and_english_buyer_gets_localized_message(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_username' => 'proof_support', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 2, 'reserved_stock' => 0]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'credential', null, null, ['buyer@example.test | pass']);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product->fresh(), 1);

        $this->postTelegramWebhook($this->callbackUpdate(231, 'cb-manual', 'checkout'))->assertOk();
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
            && $r['text'] === 'Pembayaran ditolak. Pembeli sudah diberi info.');
        $buyerMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && str_contains((string) ($request['text'] ?? ''), $order->invoice_number));
        $this->assertCount(1, $buyerMessages);
        $buyerText = (string) ($buyerMessages->first()[0]['text'] ?? '');
        $this->assertStringContainsString('Payment could not be accepted', $buyerText);
        $this->assertStringContainsString('Status: Rejected by admin', $buyerText);
        $this->assertStringContainsString('Possible reasons:', $buyerText);
        $this->assertStringContainsString('resend a clearer payment proof', $buyerText);
        $this->assertStringContainsString('@proof_support', $buyerText);
        $this->assertStringNotContainsString('Pembayaran belum bisa diterima', $buyerText);
        $this->assertStringNotContainsString('Ditolak admin', $buyerText);
        $this->assertStringNotContainsString('Kemungkinan penyebab', $buyerText);
        $this->assertStringNotContainsString('kirim ulang bukti', $buyerText);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains((string) ($r['text'] ?? ''), 'buyer@example.test | pass'));
    }

    public function test_payment_photo_is_forwarded_to_admin_and_admin_approval_sends_receipt(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(211, 'cb-manual', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(212, $order->invoice_number, ['username' => 'buyer']))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && ($r['chat_id'] ?? null) === '999'
            && ($r['photo'] ?? null) === 'large-photo'
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
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Produk terjual'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran disetujui'));

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
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'AdamSecret', 'locale' => 'id']);
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
        $this->assertStringContainsString('+ 1 other products', $copy);
        $this->assertStringContainsString('Successful orders: #1', $copy);
        $this->assertStringNotContainsString($order->invoice_number, $copy);
        $this->assertStringNotContainsString('12000', $copy);
        $this->assertStringNotContainsString('34000', $copy);
    }

    public function test_sales_proof_post_failure_is_recorded_and_can_retry_up_to_success(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'RetryBuyer']);
        $product = Product::factory()->create(['name' => 'Retry Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Retry Product');
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

    public function test_sales_proof_post_refuses_unpaid_order_without_sending_public_message(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'UnpaidBuyer']);
        $product = Product::factory()->create(['name' => 'Unpaid Product']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Unpaid Product', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $this->fakeTelegramHttp(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        Http::assertNothingSent();
        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertSame(1, $post->attempts);
        $this->assertStringContainsString('paid', (string) $post->last_error);
    }

    public function test_sales_proof_post_refuses_paid_order_missing_sale_proof(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'MissingSaleBuyer']);
        $product = Product::factory()->create(['name' => 'Missing Sale Product']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Missing Sale Product', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $this->fakeTelegramHttp(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 502]])]);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        Http::assertNothingSent();
        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertStringContainsString('sale proof', (string) $post->last_error);
    }

    public function test_sales_proof_post_refuses_paid_order_with_mismatched_sale_proof(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'MismatchBuyer']);
        $product = Product::factory()->create(['name' => 'Mismatch Product']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Mismatch Product', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        InventoryMovement::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale, 'quantity' => -2]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $this->fakeTelegramHttp(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 503]])]);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        Http::assertNothingSent();
        $post = $order->salesProofPost()->firstOrFail();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertStringContainsString('sale proof', (string) $post->last_error);
    }

    public function test_sales_proof_post_retry_skips_in_progress_send_after_worker_crash(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'CrashBuyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Crash Product', 'unit_price' => 12000, 'subtotal' => 12000]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_SENDING, 'attempts' => 1]);
        $this->fakeTelegramHttp();

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(SalesProofPost::STATUS_SENDING, $order->salesProofPost()->firstOrFail()->status);
    }

    public function test_admin_can_broadcast_message_from_bot_to_active_users(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 111, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 222, 'locale' => 'en']);
        $product = Product::factory()->create(['name' => 'Canva Pro', 'price_amount' => 75000, 'stock' => 3]);

        $this->postTelegramWebhook($this->messageUpdate(224, '/broadcast Stok Canva ready malam ini.', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) $r['text'], 'Preview broadcast'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && in_array((string) ($r['chat_id'] ?? ''), ['111', '222'], true)
            && str_contains((string) ($r['text'] ?? ''), 'Stok Canva ready malam ini.'));

        $this->postTelegramWebhook($this->callbackUpdate(225, 'broadcast-picker', 'broadcast:pick:1', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(226, 'broadcast-product', 'broadcast:select:'.$product->id.':1', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(227, 'broadcast-send', 'broadcast:send', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $localizedRecipientMessages = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
                && in_array((string) ($request['chat_id'] ?? ''), ['111', '222'], true));
        $this->assertCount(2, $localizedRecipientMessages);
        $this->assertSame([
            '111' => [
                'text' => "📣 Info dari Admin\n━━━━━━━━━━━━━━\nStok Canva ready malam ini.\n\nButuh bantuan? Balas /support ya.",
                'button_text' => '🛍️ Beli: Canva Pro',
                'callback_data' => 'product:'.$product->id,
            ],
            '222' => [
                'text' => "📣 Info from Admin\n━━━━━━━━━━━━━━\nStok Canva ready malam ini.\n\nNeed help? Reply with /support.",
                'button_text' => '🛍️ Buy: Canva Pro',
                'callback_data' => 'product:'.$product->id,
            ],
        ], $localizedRecipientMessages
            ->mapWithKeys(fn ($request): array => [(string) $request['chat_id'] => [
                'text' => (string) ($request['text'] ?? ''),
                'button_text' => $request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null,
                'callback_data' => $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null,
            ]])
            ->sortKeys()
            ->all());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) $r['text'], 'Stok Canva ready malam ini.')
            && (($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'product:'.$product->id));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) $r['text'], "✅ Broadcast selesai\n━━━━━━━━━━━━━━\nTerkirim: 3\nGagal: 0"));

        $this->postTelegramWebhook($this->callbackUpdate(228, 'broadcast-send-again', 'broadcast:send', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $recipientMessages = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendMessage')
                && in_array((string) ($record[0]['chat_id'] ?? ''), ['111', '222'], true)
                && str_contains((string) ($record[0]['text'] ?? ''), 'Stok Canva ready malam ini.'));
        $this->assertCount(2, $recipientMessages);
    }

    public function test_non_admin_cannot_approve_manual_payment_callback(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
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

    public function test_non_admin_group_member_cannot_approve_manual_payment_from_admin_chat(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '-100123', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(236, 'cb-manual-group', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(237, $order->invoice_number, ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(238, 'fake-group-approve', 'admin:approve-payment:'.$order->id, ['id' => 555, 'first_name' => 'Mallory'], -100123))->assertOk();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'fake-group-approve'
            && $r['text'] === 'Akses admin diperlukan.');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran diterima'));
    }

    public function test_admin_approve_callback_with_invalid_order_id_answers_not_found(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->callbackUpdate(216, 'bad-approve', 'admin:approve-payment:not-a-uuid', ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery')
            && $r['callback_query_id'] === 'bad-approve'
            && $r['text'] === 'Order tidak ditemukan.');
    }

    public function test_telegram_admin_cancel_command_releases_pending_order_without_payment_proof(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 1, 'reserved_stock' => 0]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'credential', null, null, ['cancel-command@example.test | pass']);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product->fresh(), 1);

        $this->postTelegramWebhook($this->callbackUpdate(250, 'checkout-cancel-command', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->messageUpdate(251, '/cancel '.$order->invoice_number, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.pending_cancelled_released', 'subject_id' => (string) $order->id]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) ($r['text'] ?? ''), 'stok sudah dilepas'));
    }

    public function test_non_admin_cannot_reject_manual_payment_callback(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(234, 'cb-manual', 'checkout'))->assertOk();
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
        $owner = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $attacker = Customer::factory()->create(['telegram_id' => 54321, 'username' => 'mallory', 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($owner), $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(216, 'cb-manual-owner', 'checkout'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(217, $order->invoice_number, ['id' => $attacker->telegram_id, 'username' => 'mallory'], 54321))->assertOk();

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && ($r['chat_id'] ?? null) === '999'
            && str_contains((string) ($r['caption'] ?? ''), $order->invoice_number));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '54321'
            && str_contains($r['text'], 'Invoice tidak ditemukan'));
    }

    public function test_receipt_notifier_sends_paid_order_receipt(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 777 && str_contains($r['text'], 'Pembayaran diterima'));
    }

    public function test_legacy_pending_order_uses_current_product_delivery_note_as_fallback(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 778, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Legacy Account', 'delivery_note' => 'Catatan fallback untuk order lama.']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Paid,
            'order_status' => OrderStatus::Completed,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => null,
            'delivery_note_snapshotted' => false,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => 'legacy@example.test|Password',
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '778'
            && str_contains((string) $r['text'], 'legacy@example.test|Password')
            && str_contains((string) $r['text'], 'Catatan fallback untuk order lama.'));
    }

    public function test_receipt_retry_skips_started_send_after_worker_crash(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777, 'locale' => 'id']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Paid,
            'telegram_receipt_send_started_at' => now(),
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertNothingSent();
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_pakasir_paid_callback_sends_telegram_receipt_once_on_duplicate_callback(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 888, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'hosted', 'pakasir');
        $payment = $order->payments()->first();
        $payload = ['project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref, 'amount' => $payment->amount, 'status' => 'completed'];
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload]),
        ]);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();

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

    private function paidOrderWithSaleProof(Customer $customer, Product $product, string $productName): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Paid,
            'order_status' => OrderStatus::Processing,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $productName,
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);
        InventoryMovement::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'type' => InventoryMovementType::Sale,
            'quantity' => -1,
        ]);

        return $order;
    }
}
