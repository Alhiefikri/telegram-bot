<?php

namespace Tests\Feature\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramBroadcast;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\SalesProofPostFormatter;
use App\Services\Telegram\SalesProofPostNumbering;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramBuyerCheckoutInstructionCleaner;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectRecovery;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptNotifier;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
        $this->assertStringContainsString('reply to that photo with /payment', $text);
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
                && str_contains($text, 'sent for review')),
            'duplicate' => $texts->contains(fn (string $text): bool => str_contains($text, 'Payment proof has already been received')
                && str_contains($text, 'awaiting admin verification')),
        ]);
        $customerCopy = $texts->implode("\n");
        $this->assertStringNotContainsString('Bukti pembayaran sudah masuk', $customerCopy);
        $this->assertStringNotContainsString('Bukti pembayaran sudah diterima', $customerCopy);
        $this->assertStringNotContainsString('menunggu verifikasi admin', $customerCopy);
    }

    public function test_malay_customer_gets_localized_payment_proof_guidance_and_forwarding(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'ms']);

        $this->postTelegramWebhook($this->messageUpdate(211, '/payment'))->assertOk();

        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BRIVA',
            'status' => PaymentStatus::Pending,
        ]);
        $this->postTelegramWebhook($this->messageUpdate(212, '/payment '.$order->invoice_number))->assertOk();

        $photo = $this->paymentPhotoUpdate(213, $order->invoice_number, ['username' => 'buyer']);
        unset($photo['message']['caption']);
        $this->postTelegramWebhook($photo)->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(214, '/payment '.$order->invoice_number))->assertOk();

        $texts = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345')
            ->map(fn (array $record): string => (string) ($record[0]['text'] ?? ''))
            ->values();
        $this->assertCount(4, $texts);
        $this->assertStringContainsString('Tiada pesanan manual yang sedang menunggu bayaran', $texts[0]);
        $this->assertStringContainsString('Bukti bayaran mesti dihantar sebagai FOTO', $texts[1]);
        $this->assertStringContainsString('Tangkapan skrin pembayaran telah diterima', $texts[2]);
        $this->assertStringContainsString('Bukti telah dihantar untuk semakan', $texts[3]);
        $this->assertStringContainsString($order->invoice_number, $texts[1]);
        $this->assertStringNotContainsString('No manual order is awaiting payment', $texts->implode("\n"));
        $this->assertStringNotContainsString('Payment proof must be sent as a PHOTO', $texts->implode("\n"));
    }

    #[DataProvider('missingPaymentInvoiceLocaleCases')]
    public function test_missing_payment_invoice_error_uses_localized_supported_history_command(string $locale, string $expectedCopy, string $historyCommand): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => $locale]);

        $this->postTelegramWebhook($this->messageUpdate(215, '/payment INV-MISSING-257', ['username' => 'buyer']))->assertOk();

        $messages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345');
        $this->assertCount(1, $messages);
        $text = (string) ($messages->first()[0]['text'] ?? '');
        $this->assertStringContainsString($expectedCopy, $text);
        $this->assertStringContainsString($historyCommand, $text);
    }

    public function test_binance_payment_photo_with_payment_caption_reaches_admin_without_auto_approval(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $update = $this->paymentPhotoUpdate(205, $order->invoice_number, ['username' => 'buyer']);
        $update['message']['caption'] = '/payment';
        $this->postTelegramWebhook($update)->assertOk();

        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(1, $adminPhotos);
        $this->assertStringContainsString('Cek pembayaran Binance Pay', (string) ($adminPhotos->first()[0]['caption'] ?? ''));
        $this->assertSame(PaymentStatus::Pending, $order->payments()->firstOrFail()->fresh()->status);
        $this->assertNotNull($order->payments()->firstOrFail()->fresh()->payment_proof_submitted_at);
    }

    public function test_pending_manual_proof_holds_stock_until_admin_rejects_after_original_deadline(): void
    {
        $this->fakeTelegramHttp();
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'qris_payment_ttl_minutes' => 1, 'is_active' => true]);

        $startedAt = now()->startOfMinute();
        Carbon::setTestNow($startedAt);
        $deadline = $startedAt->copy()->addMinute();

        try {
            $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
            $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
            app(StockAdjustmentService::class)->adjust($product, 1, 'manual payment hold regression', null, null, ['buyer@example.test | password']);
            $cart = app(CartService::class)->activeCartFor($customer);
            app(CartService::class)->addItem($cart, $product, 1);
            $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
            $stockItem = ProductStockItem::query()->where('product_id', $product->id)->sole();

            $this->postTelegramWebhook($this->paymentPhotoUpdate(206, $order->invoice_number, ['username' => 'buyer']))->assertOk();

            $this->assertTrue($order->fresh()->expires_at->equalTo($deadline));
            $this->assertTrue($order->payments()->sole()->fresh()->expired_at->equalTo($deadline));

            Carbon::setTestNow($startedAt->copy()->addMinutes(2));
            $this->artisan('orders:expire-pending')->assertSuccessful();

            $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
            $this->assertSame(PaymentStatus::Pending, $order->payments()->sole()->fresh()->status);
            $this->assertSame(1, $product->fresh()->reserved_stock);
            $this->assertSame($stockItem->id, $stockItem->fresh()->order_item_id);
            $this->assertSame(0, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());

            $this->postTelegramWebhook($this->callbackUpdate(207, 'reject-after-deadline', 'admin:reject-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
            $this->assertSame(PaymentStatus::Failed, $order->payments()->sole()->fresh()->status);
            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertNull($stockItem->fresh()->order_item_id);
            $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pending_manual_proof_holds_credential_until_admin_approves_after_original_deadline(): void
    {
        $this->fakeTelegramHttp();
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'qris_payment_ttl_minutes' => 1, 'is_active' => true]);

        $startedAt = now()->startOfMinute();
        Carbon::setTestNow($startedAt);

        try {
            $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
            $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
            app(StockAdjustmentService::class)->adjust($product, 1, 'manual payment approval regression', null, null, ['buyer@example.test | password']);
            $cart = app(CartService::class)->activeCartFor($customer);
            app(CartService::class)->addItem($cart, $product, 1);
            $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
            $stockItem = ProductStockItem::query()->where('product_id', $product->id)->sole();

            $this->postTelegramWebhook($this->paymentPhotoUpdate(208, $order->invoice_number, ['username' => 'buyer']))->assertOk();

            Carbon::setTestNow($startedAt->copy()->addMinutes(2));
            $this->artisan('orders:expire-pending')->assertSuccessful();
            $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);

            $this->postTelegramWebhook($this->callbackUpdate(209, 'approve-after-deadline', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

            $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
            $this->assertSame(PaymentStatus::Paid, $order->payments()->sole()->fresh()->status);
            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertSame(0, $product->fresh()->stock);
            $this->assertSame($stockItem->id, $stockItem->fresh()->order_item_id);
            $this->assertSame(1, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(0, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('expiredPaymentProofLocaleCases')]
    public function test_expired_binance_payment_screenshot_is_forwarded_without_reviving_the_order(string $locale, string $expectedCopy): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'late-buyer', 'locale' => $locale]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);
        $order->update([
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
        $payment->update([
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Expired,
            'expired_at' => now()->subMinute(),
            'binance_auto_verification_enabled' => true,
            'binance_order_id' => '445011487581044799',
        ]);

        $this->postTelegramWebhook($this->paymentPhotoUpdate(208, $order->invoice_number, ['username' => 'late-buyer']))->assertOk();
        $buyerMessages = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345');
        $this->assertCount(1, $buyerMessages);
        $this->assertStringContainsString($expectedCopy, (string) ($buyerMessages->first()[0]['text'] ?? ''));

        $payment->refresh();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertNotNull($payment->payment_proof_submitted_at);
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(1, $adminPhotos);
        $this->assertStringContainsString('late', strtolower((string) ($adminPhotos->first()[0]['caption'] ?? '')));
        $this->assertArrayNotHasKey('reply_markup', $adminPhotos->first()[0]);
    }

    public static function expiredPaymentProofLocaleCases(): array
    {
        return [
            'Indonesian' => ['id', 'Bukti pembayaran terlambat sudah diterima'],
            'English' => ['en', 'Late payment proof received'],
            'Malay' => ['ms', 'Bukti bayaran lewat telah diterima'],
        ];
    }

    public static function missingPaymentInvoiceLocaleCases(): array
    {
        return [
            'Indonesian' => ['id', 'Invoice tidak ditemukan', '/riwayat'],
            'English' => ['en', 'The invoice was not found', '/history'],
            'Malay' => ['ms', 'Invois tidak ditemui', '/sejarah'],
        ];
    }

    public function test_pending_auto_binance_payment_rejects_screenshot_bypass(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'auto-buyer', 'locale' => 'en']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addMinutes(10)]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 5_000_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addMinutes(10),
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'order_id',
        ]);

        $this->postTelegramWebhook($this->paymentPhotoUpdate(209, $order->invoice_number, ['username' => 'auto-buyer']))->assertOk();

        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertTrue($order->fresh()->expires_at->isFuture());
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(0, $adminPhotos);
        $buyerMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && str_contains((string) ($request['text'] ?? ''), 'Order ID'));
        $this->assertCount(1, $buyerMessage);
    }

    public function test_pending_auto_amount_unique_payment_rejects_screenshot_bypass(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'auto-buyer', 'locale' => 'en']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addMinutes(10)]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 5_001_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addMinutes(10),
            'binance_auto_verification_enabled' => true,
            'binance_verification_mode' => 'amount_unique',
        ]);

        $this->postTelegramWebhook($this->paymentPhotoUpdate(210, $order->invoice_number, ['username' => 'auto-buyer']))->assertOk();

        $stored = $payment->fresh();
        $this->assertNull($stored->payment_proof_submitted_at);
        $this->assertSame(PaymentStatus::Pending, $stored->status);
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(0, $adminPhotos);
        $buyerMessage = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && str_contains((string) ($request['text'] ?? ''), 'automatic'));
        $this->assertCount(1, $buyerMessage);
    }

    public function test_binance_payment_photo_is_forwarded_when_payment_command_is_sent_after_the_photo(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $largePhotoId = str_repeat('telegram-proof-', 20);
        $photo = $this->paymentPhotoUpdate(205, $order->invoice_number, ['username' => 'buyer'], 12345, 'small-photo', $largePhotoId);
        unset($photo['message']['caption']);
        $this->postTelegramWebhook($photo)->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(206, '/payment', ['username' => 'buyer']))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === $largePhotoId);
        $payment = $order->payments()->firstOrFail()->fresh();
        $this->assertNotNull($payment->payment_proof_submitted_at);
        $this->assertGreaterThan(255, strlen((string) $payment->getRawOriginal('payment_proof_file_id')));
    }

    public function test_group_photo_without_caption_is_not_reused_as_private_payment_proof(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $groupPhoto = $this->paymentPhotoUpdate(206, $order->invoice_number, ['id' => 12345, 'username' => 'buyer'], -100987, 'group-small-photo', 'group-large-photo');
        unset($groupPhoto['message']['caption']);
        $groupPhoto['message']['chat']['type'] = 'group';

        $this->postTelegramWebhook($groupPhoto)->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(207, '/payment', ['id' => 12345, 'username' => 'buyer']))->assertOk();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === 'group-large-photo');
        $this->assertNull($order->payments()->firstOrFail()->fresh()->payment_proof_submitted_at);
    }

    #[Group('postgres-release')]
    public function test_binance_payment_photo_with_payment_caption_reaches_admin_through_the_database_queue(): void
    {
        config()->set('queue.default', 'database');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $update = $this->paymentPhotoUpdate(206, $order->invoice_number, ['username' => 'buyer']);
        $update['message']['caption'] = '/payment';
        $this->postTelegramWebhook($update)->assertOk();

        $storedUpdate = TelegramUpdate::firstOrFail();
        $this->assertNull($storedUpdate->processed_at);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('jobs', ['queue' => 'notifications']);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'notifications',
            '--once' => true,
            '--tries' => 1,
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === 'large-photo');
        $this->assertNotNull($storedUpdate->fresh()->processed_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_binance_payment_proof_replied_to_with_payment_command_reaches_admin(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'en']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $update = $this->messageUpdate(206, '/payment '.$order->invoice_number, ['username' => 'buyer']);
        $update['message']['reply_to_message'] = [
            'message_id' => 205,
            'photo' => [
                ['file_id' => 'small-proof', 'width' => 100, 'height' => 100],
                ['file_id' => 'replied-proof', 'width' => 800, 'height' => 800],
            ],
        ];

        $this->postTelegramWebhook($update)->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === 'replied-proof');
    }

    public function test_binance_payment_proof_with_ambiguous_admin_delivery_stays_in_review_without_a_duplicate_send(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::sequence()
                ->push(['ok' => false, 'description' => 'temporary Telegram failure'], 500)
                ->push(['ok' => true]),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
        ]);

        $failedUpdate = $this->paymentPhotoUpdate(206, $order->invoice_number, ['username' => 'buyer']);
        $failedUpdate['message']['caption'] = '/payment';
        $this->postTelegramWebhook($failedUpdate)->assertOk();

        $this->assertNotNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertSame('large-photo', $payment->fresh()->payment_proof_file_id);
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
        $this->assertNull($payment->fresh()->payment_proof_forwarded_at);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && str_contains((string) ($request['text'] ?? ''), 'Bukti pembayaran sudah masuk'));

        $this->artisan('telegram:forward-payment-proofs', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:forward-payment-proofs completed: forwarded=0 failed=0')
            ->assertSuccessful();

        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === 'large-photo');
        $this->assertCount(1, $adminPhotos);
    }

    public function test_approved_binance_payment_deletes_the_buyers_checkout_instruction(): void
    {
        config()->set('services.binance.pay_id', '1251268604');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 701]]),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 1_500_000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->firstOrFail(), CheckoutPaymentIntents::binancePay());

        (new SendBinanceCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));
        $this->postTelegramWebhook($this->paymentPhotoUpdate(207, $order->invoice_number, ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(208, 'approve-binance', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && ($request['message_id'] ?? null) === 701);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
    }

    public function test_binance_checkout_instruction_is_deleted_when_order_completes_before_message_id_is_saved(): void
    {
        config()->set('services.binance.pay_id', '1251268604');
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 1_500_000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->firstOrFail(), CheckoutPaymentIntents::binancePay());
        $completedDuringInstructionSend = false;

        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$completedDuringInstructionSend, $order) {
            if (str_ends_with($request->url(), '/sendPhoto')) {
                if (! $completedDuringInstructionSend) {
                    $completedDuringInstructionSend = true;
                    app(OrderStateMachine::class)->completeManualOrder(Order::findOrFail($order->id));
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 703]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 704]]);
        });

        (new SendBinanceCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));

        $this->assertTrue($completedDuringInstructionSend);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && ($request['message_id'] ?? null) === 703);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
    }

    public function test_rejected_binance_payment_deletes_the_buyers_checkout_instruction(): void
    {
        config()->set('services.binance.pay_id', '1251268604');
        config()->set('services.telegram.admin_user_id', '999');
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 702]]),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 1_500_000]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->firstOrFail(), CheckoutPaymentIntents::binancePay());

        (new SendBinanceCheckoutInstructions($order->id))->handle(app(TelegramCheckoutIntents::class));
        $this->postTelegramWebhook($this->paymentPhotoUpdate(209, $order->invoice_number, ['username' => 'buyer']))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(210, 'reject-binance', 'admin:reject-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) ($request['chat_id'] ?? '') === '12345'
            && ($request['message_id'] ?? null) === 702);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
    }

    public function test_binance_checkout_instruction_cleanup_only_deletes_once(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/deleteMessage' => Http::response(['ok' => true]),
        ]);
        $order = $this->completedTrackedBinanceOrder(['manual_checkout_customer_notification_message_id' => 704]);
        $job = new DeleteBuyerBinanceCheckoutInstruction($order->id);

        $job->handle(app(TelegramBuyerCheckoutInstructionCleaner::class));
        $job->handle(app(TelegramBuyerCheckoutInstructionCleaner::class));

        $deletes = Http::recorded(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));
        $this->assertCount(1, $deletes);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
    }

    public function test_binance_checkout_instruction_cleanup_treats_missing_telegram_message_as_deleted(): void
    {
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/deleteMessage' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message to delete not found',
            ], 400),
        ]);
        $order = $this->completedTrackedBinanceOrder(['manual_checkout_customer_notification_message_id' => 705]);

        (new DeleteBuyerBinanceCheckoutInstruction($order->id))
            ->handle(app(TelegramBuyerCheckoutInstructionCleaner::class));

        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notification_deletion_started_at);
        $this->assertNull($order->fresh()->manual_checkout_customer_notification_deletion_token);
    }

    public function test_recovery_skips_active_binance_checkout_instruction_cleanup_claim(): void
    {
        config()->set('services.telegram.claim_lease_seconds', 60);
        Queue::fake();
        $this->completedTrackedBinanceOrder([
            'manual_checkout_customer_notification_message_id' => 706,
            'manual_checkout_customer_notification_deletion_started_at' => now(),
            'manual_checkout_customer_notification_deletion_token' => '7d395b5b-57f7-4183-8113-0a0890df49b2',
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=0')
            ->assertSuccessful();

        Queue::assertNotPushed(DeleteBuyerBinanceCheckoutInstruction::class);
    }

    public function test_recovery_dispatches_stale_binance_checkout_instruction_cleanup_claim(): void
    {
        config()->set('services.telegram.claim_lease_seconds', 60);
        Queue::fake()->except(DeleteBuyerCheckoutMessages::class);
        $this->fakeTelegramHttp();
        $order = $this->completedTrackedBinanceOrder([
            'manual_checkout_customer_notification_message_id' => 707,
            'manual_checkout_customer_notification_deletion_started_at' => now()->subSeconds(61),
            'manual_checkout_customer_notification_deletion_token' => '6f32f78e-e3bd-4dff-a534-f07cc08922dd',
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) $request['chat_id'] === '12345'
            && (int) $request['message_id'] === 707);
        $this->assertNotNull($order->fresh()->manual_checkout_customer_notification_deleted_at);
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])->assertSuccessful();
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/deleteMessage')));
    }

    public function test_unforwarded_binance_payment_proof_is_recovered_by_scheduler(): void
    {
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'amount' => 500_000,
            'currency' => 'USDT',
            'status' => PaymentStatus::Pending,
            'payment_proof_file_id' => 'stored-proof',
            'payment_proof_submitted_at' => now(),
            'payment_proof_admin_chat_id' => '111',
        ]);

        $this->artisan('telegram:forward-payment-proofs', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:forward-payment-proofs completed: forwarded=1 failed=0')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['photo'] ?? null) === 'stored-proof');
        $this->assertNotNull($payment->fresh()->payment_proof_forwarded_at);
        $this->assertSame('999', $payment->fresh()->payment_proof_admin_chat_id);

        $this->artisan('telegram:forward-payment-proofs', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:forward-payment-proofs completed: forwarded=0 failed=0')
            ->assertSuccessful();
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(1, $adminPhotos);
    }

    public function test_english_paid_account_receipt_localizes_delivery_note_label_and_contains_only_the_buyers_secret(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_username' => 'store_support', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $this->fakeTelegramHttp();

        $buyer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => 54321, 'username' => 'other', 'locale' => 'id']);
        $product = Product::factory()->create([
            'name' => 'Netflix Premium',
            'delivery_note' => 'Rotate the password and MFA after signing in.',
            'usage_guide_title' => 'Netflix setup guide',
            'usage_guide_url' => '/netflix/setup',
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        app(StockAdjustmentService::class)->adjust($product, 1, 'seed credential', null, null, [
            'email: buyer@example.test | password: Secret-123',
        ]);

        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($buyer), $product->fresh(), 1);
        $this->postTelegramWebhook($this->callbackUpdate(221, 'cb-checkout-secret', 'checkout'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1221, 'cb-checkout-secret-qris', 'checkout:current'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->callbackUpdate(222, 'approve-secret', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $receiptPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '12345'
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'Payment received'));
        $this->assertCount(1, $receiptPhotos);
        $receiptPhoto = $receiptPhotos->first()[0];
        $receiptText = (string) $this->telegramRequestField($receiptPhoto, 'caption');
        $this->assertStringContainsString('Payment received', $receiptText);
        $this->assertStringContainsString('buyer@example.test', $receiptText);
        $this->assertStringContainsString('Secret-123', $receiptText);
        $this->assertStringContainsString('Delivery note:', $receiptText);
        $this->assertStringContainsString('Rotate the password and MFA after signing in.', $receiptText);
        $this->assertStringContainsString('Netflix setup guide', $receiptText);
        $this->assertStringNotContainsString(url('/netflix/setup'), $receiptText);
        $this->assertStringContainsString('Thank you for shopping at LHIE STORE.', $receiptText);
        $this->assertStringNotContainsString('🌙', $receiptText);
        $this->assertStringNotContainsString('Need help signing in and securing the account?', $receiptText);
        $this->assertStringNotContainsString('/gpt/panduan-login-akun', $receiptText);

        $guideMarkup = json_decode(
            (string) $this->telegramRequestField($receiptPhoto, 'reply_markup'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('Netflix setup guide', data_get($guideMarkup, 'inline_keyboard.0.0.text'));
        $this->assertSame(url('/netflix/setup'), data_get($guideMarkup, 'inline_keyboard.0.0.url'));
        $this->assertSame('Contact Support', data_get($guideMarkup, 'inline_keyboard.1.0.text'));
        $this->assertSame('https://t.me/store_support', data_get($guideMarkup, 'inline_keyboard.1.0.url'));

        $visibleReceipt = json_encode([
            'caption' => $receiptText,
            'markup' => $guideMarkup,
        ], JSON_THROW_ON_ERROR);
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

    #[DataProvider('receiptLocaleCases')]
    public function test_paid_receipt_localizes_system_copy_for_each_customer_locale(array $copy): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $copy['locale']]);
        $product = Product::factory()->create([
            'name' => 'Locale Receipt Product',
            'delivery_note' => 'merchant-delivery-note::KEEP-OPAQUE',
            'usage_guide_title' => 'Locale guide',
            'usage_guide_url' => '/locale-guide',
        ]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 25000,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'amount' => 25000,
            'paid_at' => now(),
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => $product->delivery_note,
            'delivery_note_snapshotted' => true,
            'quantity' => 1,
            'subtotal' => 25000,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => 'buyer@example.test | password: Locale-Secret',
        ]);
        app(TelegramReceiptNotifier::class)->send($order);
        $photos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains($request->body(), 'filename="nota-b-')
            && str_contains($request->body(), "\r\n12345\r\n"));
        $this->assertCount(1, $photos);
        $photo = $photos->sole()[0];
        $text = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertStringContainsString($copy['title'], $text);
        $this->assertStringContainsString($copy['deliveryNote'], $text);
        $this->assertStringContainsString('merchant-delivery-note::KEEP-OPAQUE', $text);
        $this->assertStringContainsString('buyer@example.test', $text);
        $this->assertStringContainsString('Locale-Secret', $text);
        $this->assertStringContainsString($copy['thanks'], $text);
        $this->assertStringNotContainsString($copy['otherTitle'], $text);
        $this->assertStringNotContainsString($copy['otherDeliveryNote'], $text);
        $this->assertStringNotContainsString(url('/locale-guide'), $text);

        $markup = json_decode(
            (string) $this->telegramRequestField($photo, 'reply_markup'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame(url('/locale-guide'), data_get($markup, 'inline_keyboard.0.0.url'));
    }

    public static function receiptLocaleCases(): array
    {
        return [
            'Indonesian' => [[
                'locale' => 'id',
                'title' => '✅ Pembayaran diterima',
                'otherTitle' => '✅ Payment received',
                'accountDetails' => '🔐 Data akun',
                'otherAccountDetails' => '🔐 Account details',
                'deliveryNote' => '📌 Catatan pengiriman:',
                'otherDeliveryNote' => '📌 Delivery note:',
                'thanks' => 'Terima kasih sudah belanja di LHIE STORE.',
            ]],
            'English' => [[
                'locale' => 'en',
                'title' => '✅ Payment received',
                'otherTitle' => '✅ Pembayaran diterima',
                'accountDetails' => '🔐 Account details',
                'otherAccountDetails' => '🔐 Data akun',
                'deliveryNote' => '📌 Delivery note:',
                'otherDeliveryNote' => '📌 Catatan pengiriman:',
                'thanks' => 'Thank you for shopping at LHIE STORE.',
            ]],
            'Malay' => [[
                'locale' => 'ms',
                'title' => '✅ Bayaran diterima',
                'otherTitle' => '✅ Payment received',
                'accountDetails' => '🔐 Butiran akaun',
                'otherAccountDetails' => '🔐 Account details',
                'deliveryNote' => '📌 Nota penghantaran:',
                'otherDeliveryNote' => '📌 Delivery note:',
                'thanks' => 'Terima kasih kerana membeli-belah di LHIE STORE.',
            ]],
        ];
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
        $this->postTelegramWebhook($this->callbackUpdate(1231, 'cb-manual-qris', 'checkout:current'))->assertOk();
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
        $this->postTelegramWebhook($this->callbackUpdate(1211, 'cb-manual-qris', 'checkout:current'))->assertOk();
        $order = Order::firstOrFail();

        $this->postTelegramWebhook($this->paymentPhotoUpdate(212, $order->invoice_number, ['username' => 'buyer']))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && ($r['chat_id'] ?? null) === '999'
            && ($r['photo'] ?? null) === 'large-photo'
            && str_contains((string) ($r['caption'] ?? ''), 'Bukti pembayaran masuk')
            && str_contains(json_encode($r->data()), 'admin:approve-payment:'.$order->id)
            && str_contains(json_encode($r->data()), 'admin:reject-payment:'.$order->id));

        $this->postTelegramWebhook($this->callbackUpdate(213, 'approve-payment', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999, 'private', 123456))->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage')
            && (string) ($request['chat_id'] ?? '') === '999'
            && ($request['message_id'] ?? null) === 123456);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($r, 'chat_id') === '12345'
            && str_contains((string) $this->telegramRequestField($r, 'caption'), 'Pembayaran diterima')
            && str_contains((string) $this->telegramRequestField($r, 'caption'), 'Email Account'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Produk terjual'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '999'
            && str_contains((string) ($r['text'] ?? ''), 'Pembayaran disetujui'));

        $this->postTelegramWebhook($this->callbackUpdate(219, 'approve-payment-again', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $receiptCount = 0;
        Http::assertSent(function ($r) use (&$receiptCount) {
            if (str_ends_with($r->url(), '/sendPhoto')
                && (string) $this->telegramRequestField($r, 'chat_id') === '12345'
                && str_contains((string) $this->telegramRequestField($r, 'caption'), 'Pembayaran diterima')) {
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
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 88]]),
        ]);
        app(CustomEmojiCapability::class)->markAvailable();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create([
            'telegram_id' => 123456789,
            'first_name' => 'Ciapnih',
            'username' => 'ciapnih',
            'locale' => 'id',
        ]);
        $telegramFrom = ['id' => 123456789, 'first_name' => 'Ciapnih', 'username' => 'ciapnih'];
        $first = Product::factory()->create([
            'name' => 'Email Account',
            'stock' => 5,
            'price_amount' => 12000,
            'delivery_note' => 'Buyer-only product note',
            'usage_guide_title' => 'Buyer-only guide',
            'usage_guide_url' => 'https://guide.example.test/private',
            'icon' => '🧩',
            'custom_emoji_id' => '5368324170671202286',
        ]);
        $family = ProductFamily::factory()->create(['name' => 'Canva', 'slug' => 'canva-family-icon-proof']);
        Product::factory()->create([
            'name' => 'Canva Family Icon',
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Family Icon',
            'icon' => '🧩',
            'custom_emoji_id' => '5368324170671202286',
            'stock' => 0,
            'reserved_stock' => 0,
        ]);
        $second = Product::factory()->create([
            'name' => 'Canva Pro',
            'product_family_id' => $family->id,
            'family_sort_order' => 2,
            'family_option_label' => 'Pro',
            'icon' => '🌸',
            'stock' => 5,
            'price_amount' => 34000,
        ]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $first, 1);
        app(CartService::class)->addItem($cart, $second, 1);

        $this->postTelegramWebhook($this->callbackUpdate(301, 'cb-manual-proof', 'checkout', from: $telegramFrom, chatId: 123456789))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1301, 'cb-manual-proof-qris', 'checkout:current', from: $telegramFrom, chatId: 123456789))->assertOk();
        $order = Order::firstOrFail();
        ProductStockItem::create([
            'product_id' => $first->id,
            'order_item_id' => $order->orderItems()->where('product_id', $first->id)->sole()->id,
            'secret' => 'buyer@example.test|Buyer-password|Buyer Auth Token|https://otp.example.test/private',
        ]);
        $this->postTelegramWebhook($this->paymentPhotoUpdate(302, $order->invoice_number, $telegramFrom, chatId: 123456789))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(303, 'approve-proof', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();
        app(CustomEmojiCapability::class)->markUnavailable(
            'Bad Request: custom emoji is unavailable in this bot chat',
            CustomEmojiCapability::SCOPE_MESSAGES,
        );
        app()->call([new PublishSalesProofPost($order->id), 'handle']);
        $this->postTelegramWebhook($this->callbackUpdate(304, 'approve-proof-again', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $this->assertSame(1, SalesProofPost::where('status', SalesProofPost::STATUS_SENT)->count(), (string) SalesProofPost::first()?->last_error);
        $this->assertSame('QR_MANUAL', $order->payments()->sole()->method);
        $copy = app(SalesProofPostFormatter::class)->format(
            app(PaidInvoiceOrderPresentationAdapter::class)->present($order->fresh(['customer', 'orderItems', 'payments'])),
            1,
        );
        $this->assertStringContainsString('ORDER PAYMENT CONFIRMED', $copy);
        $this->assertStringContainsString('Buyer: Cia***', $copy);
        $this->assertStringNotContainsString('Buyer ID:', $copy);
        $this->assertStringContainsString('Paid via: QR Manual', $copy);
        $this->assertStringNotContainsString('Paid via: QR_MANUAL', $copy);
        $this->assertStringContainsString('Paid via:', $copy);
        $this->assertStringContainsString('Completed:', $copy);
        $this->assertStringContainsString('Items:', $copy);
        $this->assertStringContainsString('🧩</tg-emoji> Email Account × 1', $copy);
        $this->assertStringContainsString('🧩</tg-emoji> Canva Pro × 1', $copy);
        $familyOrderItem = $order->orderItems()->where('product_id', $second->id)->sole();
        $this->assertSame('🧩', $familyOrderItem->product_icon);
        $this->assertSame('5368324170671202286', $familyOrderItem->product_custom_emoji_id);
        $this->assertStringContainsString('Order ID: '.$order->invoice_number, $copy);
        $this->assertStringContainsString('Quantity: 2', $copy);
        $this->assertStringContainsString('Subtotal: Rp 46.000', $copy);
        $this->assertStringContainsString('Amount paid: Rp 46.000', $copy);
        $this->assertStringContainsString('Successful orders: #1', $copy);
        $this->assertStringContainsString('Thank you for choosing Lhie Store.', $copy);
        $this->assertStringNotContainsString('AdamSecret', $copy);
        $this->assertStringNotContainsString('12345', $copy);

        $channelPhotos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '-100123');
        $this->assertCount(1, $channelPhotos);
        $channelEffect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
            ->where('source_id', $order->id)
            ->sole();
        $this->assertSame('store_channel', $channelEffect->target_type);
        $this->assertSame('en', $channelEffect->locale);
        $this->assertSame('sendPhoto', $channelEffect->telegram_method);
        $channelCaption = (string) $this->telegramRequestField($channelPhotos->first()[0], 'caption');
        $this->assertStringContainsString('ORDER PAYMENT CONFIRMED', $channelCaption);
        $this->assertStringContainsString('🧩 Email Account × 1', $channelCaption);
        $this->assertStringContainsString('🧩 Canva Pro × 1', $channelCaption);
        $channelCaptionEntities = json_decode(
            (string) $this->telegramRequestField($channelPhotos->first()[0], 'caption_entities'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $familyAndProductEmojiEntities = collect($channelCaptionEntities)->filter(
            static fn (array $entity): bool => ($entity['type'] ?? null) === 'custom_emoji'
                && ($entity['custom_emoji_id'] ?? null) === '5368324170671202286',
        );
        $this->assertCount(2, $familyAndProductEmojiEntities);
        $this->assertStringContainsString('Buyer: Cia***', $channelCaption);
        $this->assertStringNotContainsString('Buyer ID:', $channelCaption);
        $this->assertStringContainsString('Shop Now', (string) $this->telegramRequestField($channelPhotos->first()[0], 'reply_markup'));
        $this->assertStringContainsString('Thank you for choosing Lhie Store.', $channelCaption);
        $this->assertStringNotContainsString('Ciapnih', $channelCaption);
        $this->assertStringNotContainsString('ciapnih', $channelCaption);
        $this->assertStringNotContainsString('12345', $channelCaption);
        foreach ([
            'buyer@example.test',
            'Buyer-password',
            'Buyer Auth Token',
            'https://otp.example.test/private',
            'Buyer-only product note',
            'Buyer-only guide',
            'https://guide.example.test/private',
        ] as $buyerOnlyValue) {
            $this->assertStringNotContainsString($buyerOnlyValue, $channelCaption);
        }
    }

    /** @return array<string, array{int, string|null}> */
    public static function stockSignalCaptionCases(): array
    {
        return [
            'units remain' => [3, '🔥 Only 2 left in stock'],
            'last unit sold' => [1, null],
        ];
    }

    #[DataProvider('stockSignalCaptionCases')]
    public function test_sales_proof_caption_announces_remaining_units_only_while_stock_lasts(int $stock, ?string $expectedLine): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 88]]),
        ]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create([
            'telegram_id' => 123456789,
            'first_name' => 'Gradit',
            'username' => 'gradit',
            'locale' => 'id',
        ]);
        $telegramFrom = ['id' => 123456789, 'first_name' => 'Gradit', 'username' => 'gradit'];
        $product = Product::factory()->create(['name' => 'Gemini Pro Voucher', 'stock' => $stock, 'price_amount' => 8000]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        $this->postTelegramWebhook($this->callbackUpdate(401, 'cb-stock-proof', 'checkout', from: $telegramFrom, chatId: 123456789))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(1401, 'cb-stock-proof-qris', 'checkout:current', from: $telegramFrom, chatId: 123456789))->assertOk();
        $order = Order::firstOrFail();
        $this->postTelegramWebhook($this->paymentPhotoUpdate(402, $order->invoice_number, $telegramFrom, chatId: 123456789))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(403, 'approve-stock-proof', 'admin:approve-payment:'.$order->id, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();
        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        $this->assertSame($stock - 1, $product->refresh()->availableStock());

        $channelPhotos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '-100123');
        $this->assertCount(1, $channelPhotos);
        $caption = (string) $this->telegramRequestField($channelPhotos->first()[0], 'caption');

        if ($expectedLine === null) {
            $this->assertStringNotContainsString('left in stock', $caption);

            return;
        }

        $this->assertStringContainsString($expectedLine, $caption);
    }

    public function test_already_materialized_text_sales_proof_remains_dispatchable_after_photo_cutover(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'LegacyBuyer']);
        $product = Product::factory()->create([
            'name' => 'Legacy Product',
            'icon' => '🧩',
            'custom_emoji_id' => '5368324170671202286',
        ]);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Legacy Product');
        $order->orderItems()->sole()->update([
            'product_icon' => '🧩',
            'product_custom_emoji_id' => '5368324170671202286',
        ]);
        $post = SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'store_channel',
            'telegram_chat_id' => '-100123',
            'queue' => TelegramOutboundEffectService::SALES_PROOF_QUEUE,
            'telegram_method' => 'sendMessage',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF_LEGACY,
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 701]]),
        ]);
        app(CustomEmojiCapability::class)->markAvailable();

        app()->call([new PublishSalesProofPost($order->id, effectId: $effect->id), 'handle']);

        $this->assertSame(SalesProofPost::STATUS_SENT, $post->fresh()->status);
        $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SALES_PROOF_LEGACY, $effect->fresh()->template_version);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $channelMessage = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $channelText = (string) $this->telegramRequestField($channelMessage, 'text');
        $this->assertStringContainsString('🧩 Legacy Product', $channelText);
        $channelEntities = $this->telegramRequestField($channelMessage, 'entities');
        if (is_string($channelEntities)) {
            $channelEntities = json_decode($channelEntities, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->assertIsArray($channelEntities);
        $this->assertTrue(collect($channelEntities)->contains(
            static fn (array $entity): bool => ($entity['type'] ?? null) === 'custom_emoji'
                && ($entity['custom_emoji_id'] ?? null) === '5368324170671202286',
        ));
    }

    public function test_migrated_photo_template_effect_is_normalized_before_delivery(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'MigratedBuyer']);
        $product = Product::factory()->create(['name' => 'Migrated Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Migrated Product');
        $post = SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_PENDING,
        ]);
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'store_channel',
            'telegram_chat_id' => '-100123',
            'queue' => TelegramOutboundEffectService::SALES_PROOF_QUEUE,
            'telegram_method' => 'sendMessage',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 702],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 703],
            ]),
        ]);

        app()->call([new PublishSalesProofPost($order->id, effectId: $effect->id), 'handle']);

        $effect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('sendPhoto', $effect->telegram_method);
        $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SALES_PROOF, $effect->template_version);
        $this->assertNull($effect->claim_token);
        $this->assertSame('702', $effect->telegram_message_id);
        $this->assertSame(SalesProofPost::STATUS_SENT, $post->fresh()->status);

        $photos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $photos);
        $this->assertCount(0, $messages);

        $photoRequest = $photos->first()[0];
        $caption = (string) $this->telegramRequestField($photoRequest, 'caption');
        $photoBytes = $this->telegramRequestField($photoRequest, 'photo');
        $dimensions = is_string($photoBytes) ? @getimagesizefromstring($photoBytes) : false;

        $this->assertStringContainsString('ORDER PAYMENT CONFIRMED', $caption);
        $this->assertStringContainsString('Thank you for choosing Lhie Store.', $caption);
        $this->assertStringContainsString('Shop Now', (string) $this->telegramRequestField($photoRequest, 'reply_markup'));
        $this->assertIsArray($dimensions);
        $this->assertSame('image/png', $dimensions['mime'] ?? null);
        $this->assertSame(720, $dimensions[0] ?? null);
        $this->assertSame(720, $dimensions[1] ?? null);
    }

    public function test_migrated_failed_photo_template_retries_as_photo_without_duplicate_send(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'MigratedRetryBuyer']);
        $product = Product::factory()->create(['name' => 'Migrated Retry Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Migrated Retry Product');
        $post = SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'temporary channel failure',
        ]);
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'store_channel',
            'telegram_chat_id' => '-100123',
            'queue' => TelegramOutboundEffectService::SALES_PROOF_QUEUE,
            'telegram_method' => 'sendMessage',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
            'state' => TelegramOutboundEffectState::Failed,
            'attempts' => 1,
            'next_attempt_at' => now(),
            'last_error' => 'Telegram delivery failed before completion.',
        ]);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 704],
            ]),
        ]);

        $job = new PublishSalesProofPost($order->id, effectId: $effect->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $effect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->state);
        $this->assertSame('sendPhoto', $effect->telegram_method);
        $this->assertSame(2, $effect->attempts);
        $this->assertSame('704', $effect->telegram_message_id);
        $this->assertSame(SalesProofPost::STATUS_SENT, $post->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_migrated_terminal_sales_proof_states_are_not_normalized_or_resent(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        Http::fake();

        foreach ([
            [TelegramOutboundEffectState::Sent, SalesProofPost::STATUS_SENT, '705'],
            [TelegramOutboundEffectState::Unknown, SalesProofPost::STATUS_UNKNOWN, null],
            [TelegramOutboundEffectState::ManualReview, SalesProofPost::STATUS_MANUAL_REVIEW, null],
        ] as [$state, $postStatus, $messageId]) {
            $customer = Customer::factory()->create();
            $product = Product::factory()->create(['name' => 'Terminal Product']);
            $order = $this->paidOrderWithSaleProof($customer, $product, 'Terminal Product');
            $post = SalesProofPost::create([
                'order_id' => $order->id,
                'status' => $postStatus,
                'attempts' => 1,
                'telegram_message_id' => $messageId,
            ]);
            $effect = TelegramOutboundEffect::factory()->create([
                'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
                'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
                'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
                'source_type' => Order::class,
                'source_id' => $order->id,
                'target_type' => 'store_channel',
                'telegram_chat_id' => '-100123',
                'queue' => TelegramOutboundEffectService::SALES_PROOF_QUEUE,
                'telegram_method' => 'sendMessage',
                'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
                'state' => $state,
                'attempts' => 1,
                'next_attempt_at' => null,
                'telegram_message_id' => $messageId,
            ]);

            app()->call([new PublishSalesProofPost($order->id, effectId: $effect->id), 'handle']);

            $fresh = $effect->fresh();
            $this->assertSame($state, $fresh->state);
            $this->assertSame('sendMessage', $fresh->telegram_method);
            $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SALES_PROOF, $fresh->template_version);
            $this->assertNull($fresh->claim_token);
            $this->assertSame($messageId, $fresh->telegram_message_id);
            $this->assertSame($postStatus, $post->fresh()->status);
        }

        Http::assertNothingSent();
    }

    public function test_sales_proof_repair_migration_only_normalizes_retryable_effects(): void
    {
        $effects = [];
        foreach ([
            TelegramOutboundEffectState::Pending,
            TelegramOutboundEffectState::Failed,
            TelegramOutboundEffectState::Sent,
            TelegramOutboundEffectState::Unknown,
            TelegramOutboundEffectState::ManualReview,
        ] as $state) {
            $effects[] = TelegramOutboundEffect::factory()->create([
                'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
                'telegram_method' => 'sendMessage',
                'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
                'state' => $state,
            ]);
        }

        $migration = require database_path('migrations/2026_08_22_000001_repair_sales_proof_photo_effect_contract.php');
        $migration->up();

        $this->assertSame('sendPhoto', $effects[0]->fresh()->telegram_method);
        $this->assertSame('sendPhoto', $effects[1]->fresh()->telegram_method);
        foreach (array_slice($effects, 2) as $effect) {
            $this->assertSame('sendMessage', $effect->fresh()->telegram_method);
            $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SALES_PROOF, $effect->fresh()->template_version);
        }
    }

    public function test_sales_proof_post_failure_is_recorded_and_can_retry_up_to_success(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'RetryBuyer']);
        $product = Product::factory()->create(['name' => 'Retry Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Retry Product');
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::sequence()
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
        $this->assertSame(SalesProofPost::STATUS_SENT, $post->status, (string) $post->last_error);
        $this->assertSame(2, $post->attempts);
        $this->assertSame('99', $post->telegram_message_id);
        $this->assertNull($post->last_error);
        $this->assertNotNull($post->sent_at);
    }

    public function test_outbound_recovery_redispatches_a_retryable_sales_proof_effect(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create([
            'telegram_id' => 123456789,
            'first_name' => 'Ciapnih',
            'username' => 'ciapnih',
        ]);
        $product = Product::factory()->create(['name' => 'Retry Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Retry Product');
        SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Telegram delivery failed before completion.',
        ]);
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'store_channel',
            'telegram_chat_id' => '-100123',
            'queue' => 'notifications',
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
            'state' => TelegramOutboundEffectState::Failed,
            'attempts' => 1,
            'next_attempt_at' => now()->subSecond(),
            'last_error_code' => 'telegram_delivery_failed',
            'last_error' => 'Telegram delivery failed before completion.',
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->expectsOutputToContain('telegram:recover-outbound completed cleanly: dispatched=')
            ->assertSuccessful();

        Queue::assertPushed(
            PublishSalesProofPost::class,
            fn (PublishSalesProofPost $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_sales_proof_render_failure_does_not_consume_number_before_later_success(): void
    {
        Cache::flush();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'RenderFailureBuyer']);
        $product = Product::factory()->create(['name' => 'Render Failure Product']);
        $failedOrder = $this->paidOrderWithSaleProof($customer, $product, 'Render Failure Product');
        $failedPost = SalesProofPost::create([
            'order_id' => $failedOrder->id,
            'status' => SalesProofPost::STATUS_PENDING,
        ]);
        Payment::query()
            ->where('order_id', $failedOrder->id)
            ->update(['paid_at' => null]);

        $successfulOrder = $this->paidOrderWithSaleProof($customer, $product, 'Render Failure Product');
        SalesProofPost::create([
            'order_id' => $successfulOrder->id,
            'status' => SalesProofPost::STATUS_PENDING,
        ]);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 100],
            ]),
        ]);

        app()->call([new PublishSalesProofPost($failedOrder->id), 'handle']);

        $this->assertSame(SalesProofPost::STATUS_FAILED, $failedPost->fresh()->status);
        $this->assertSame(0, app(SalesProofPostNumbering::class)->publishedCount());

        app()->call([new PublishSalesProofPost($successfulOrder->id), 'handle']);

        $channelPhotos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '-100123');
        $this->assertCount(1, $channelPhotos);
        $caption = (string) $this->telegramRequestField($channelPhotos->first()[0], 'caption');
        $this->assertStringContainsString('Successful orders: #1', $caption);
    }

    public function test_sales_proof_retry_after_success_does_not_allocate_another_number(): void
    {
        Cache::flush();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'RetryNumberBuyer']);
        $product = Product::factory()->create(['name' => 'Retry Number Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Retry Number Product');
        SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_PENDING,
        ]);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ]),
        ]);

        $job = new PublishSalesProofPost($order->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $channelPhotos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '-100123');
        $this->assertCount(1, $channelPhotos);
        $caption = (string) $this->telegramRequestField($channelPhotos->first()[0], 'caption');
        $this->assertStringContainsString('Successful orders: #1', $caption);
        $this->assertSame(1, app(SalesProofPostNumbering::class)->publishedCount());
    }

    #[DataProvider('invalidSalesProofTelegramResponses')]
    public function test_invalid_sales_proof_telegram_responses_never_mark_the_post_sent(array $response): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $customer = Customer::factory()->create(['username' => 'InvalidProofBuyer']);
        $product = Product::factory()->create(['name' => 'Invalid Proof Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Invalid Proof Product');
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response($response)]);

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        $post = SalesProofPost::query()->where('order_id', $order->id)->sole();
        $effect = TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)->sole();
        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->status);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->state);
        $this->assertNull($post->telegram_message_id);
        $this->assertNull($effect->telegram_message_id);
        $this->assertNull($post->sent_at);
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

    public function test_sales_proof_post_retry_moves_legacy_in_progress_send_to_manual_review(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'CrashBuyer']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Crash Product', 'unit_price' => 12000, 'subtotal' => 12000]);
        SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_SENDING, 'attempts' => 1]);
        $this->fakeTelegramHttp();

        app()->call([new PublishSalesProofPost($order->id), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(SalesProofPost::STATUS_UNKNOWN, $order->salesProofPost()->firstOrFail()->status);
        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', 'sales_proof.post')
            ->where('source_id', $order->id)
            ->sole();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
    }

    public function test_sales_proof_ambiguous_delivery_becomes_unknown_without_a_duplicate_send(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'AmbiguousBuyer']);
        $product = Product::factory()->create(['name' => 'Ambiguous Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Ambiguous Product');
        $post = SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $sendCount = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
        ]);

        $job = new PublishSalesProofPost($order->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
            ->where('source_id', $order->id)
            ->sole();

        $this->assertSame(1, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->state);
        $this->assertSame(SalesProofPost::STATUS_UNKNOWN, $post->fresh()->status);
        $this->assertNull($effect->claim_token);
    }

    public function test_sales_proof_lease_recovery_rejects_an_old_worker_finalize(): void
    {
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $customer = Customer::factory()->create(['username' => 'LeaseBuyer']);
        $product = Product::factory()->create(['name' => 'Lease Product']);
        $order = $this->paidOrderWithSaleProof($customer, $product, 'Lease Product');
        $post = SalesProofPost::create(['order_id' => $order->id, 'status' => SalesProofPost::STATUS_PENDING]);
        $effects = app(TelegramOutboundEffectService::class);
        $effect = $effects->recordSalesProofPost($order, $post);
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect?->getKey());

        $this->assertNotNull($claimed);
        $oldToken = (string) $claimed?->claim_token;
        $effect->fresh()->forceFill([
            'claim_token' => 'new-owner-token',
            'lease_until' => now()->subSecond(),
        ])->save();

        $this->assertNull($ledger->finalizeSent($effect->id, $oldToken, '700001'));
        $this->assertSame(1, app(TelegramOutboundEffectRecovery::class)->recoverExpiredLeases(now()));
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(SalesProofPost::STATUS_UNKNOWN, $post->fresh()->status);
    }

    public function test_admin_can_broadcast_message_from_bot_to_active_users(): void
    {
        config()->set('services.telegram.admin_user_id', '999');
        Bus::fake([SendTelegramBroadcastBatch::class]);
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

        $preview = Http::recorded(fn ($record): bool => str_contains((string) ($record['text'] ?? ''), 'Preview broadcast'))->last()[0];
        $pickCallback = $preview['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(225, 'broadcast-picker', $pickCallback, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $picker = Http::recorded(fn ($record): bool => ($record['text'] ?? null) === 'Pilih produk untuk tombol pembelian broadcast.')->last()[0];
        $selectCallback = $picker['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(226, 'broadcast-product', $selectCallback, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $selectedPreview = Http::recorded(fn ($record): bool => str_contains((string) ($record['text'] ?? ''), 'Tombol produk: 🛍️ Canva Pro'))->last()[0];
        $sendCallback = $selectedPreview['reply_markup']['inline_keyboard'][1][0]['callback_data'];
        $this->postTelegramWebhook($this->callbackUpdate(227, 'broadcast-send', $sendCallback, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $queuedBroadcast = Bus::dispatched(SendTelegramBroadcastBatch::class)->sole();
        $queuedBroadcast->handle(app(TelegramBroadcastService::class));

        $localizedRecipientMessages = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
                && in_array((string) ($request['chat_id'] ?? ''), ['111', '222'], true));
        $this->assertCount(2, $localizedRecipientMessages);
        $this->assertSame([
            '111' => [
                'text' => "📣 Info dari Admin\n━━━━━━━━━━━━━━\nStok Canva ready malam ini.\n\nButuh bantuan? Balas /support ya.",
                'button_text' => '🛍️ Canva Pro · 🛍️ Beli',
                'callback_data' => 'product:'.$product->id,
            ],
            '222' => [
                'text' => "📣 Info from Admin\n━━━━━━━━━━━━━━\nStok Canva ready malam ini.\n\nNeed help? Reply with /support.",
                'button_text' => '🛍️ Canva Pro · 🛍️ Buy',
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
            && str_contains((string) $r['text'], "✅ Broadcast masuk antrean\n━━━━━━━━━━━━━━")
            && str_contains((string) $r['text'], 'Pengiriman berjalan di background.'));

        $this->postTelegramWebhook($this->callbackUpdate(228, 'broadcast-send-again', $sendCallback, ['id' => 999, 'first_name' => 'Admin'], 999))->assertOk();

        $recipientMessages = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/sendMessage')
                && in_array((string) ($record[0]['chat_id'] ?? ''), ['111', '222'], true)
                && str_contains((string) ($record[0]['text'] ?? ''), 'Stok Canva ready malam ini.'));
        $this->assertCount(2, $recipientMessages);
        $this->assertSame(1, TelegramBroadcast::query()->count());
        $this->assertCount(1, Bus::dispatched(SendTelegramBroadcastBatch::class));
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
        $this->postTelegramWebhook($this->callbackUpdate(1214, 'cb-manual-qris', 'checkout:current'))->assertOk();
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
        $this->postTelegramWebhook($this->callbackUpdate(1236, 'cb-manual-group-qris', 'checkout:current'))->assertOk();
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
        $this->postTelegramWebhook($this->callbackUpdate(1250, 'checkout-cancel-command-qris', 'checkout:current'))->assertOk();
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
        $this->postTelegramWebhook($this->callbackUpdate(1234, 'cb-manual-qris', 'checkout:current'))->assertOk();
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
        $this->postTelegramWebhook($this->callbackUpdate(1216, 'cb-manual-owner-qris', 'checkout:current'))->assertOk();
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
        Queue::fake([DeleteBuyerCheckoutMessages::class]);
        config()->set('services.telegram.admin_contact_url', 'https://t.me/store_support');
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 777
            && str_contains($r['text'], 'Pembayaran diterima')
            && count(data_get($r->data(), 'reply_markup.inline_keyboard', [])) === 1
            && data_get($r->data(), 'reply_markup.inline_keyboard.0.0.text') === 'Hubungi Support'
            && data_get($r->data(), 'reply_markup.inline_keyboard.0.0.url') === 'https://t.me/store_support');
        Queue::assertPushedOn('notifications', DeleteBuyerCheckoutMessages::class, fn (DeleteBuyerCheckoutMessages $job): bool => $job->orderId === $order->id);
    }

    public function test_paid_klikqris_cleanup_deletes_checkout_and_latest_order_detail_messages(): void
    {
        $this->fakeTelegramHttp();
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'manual_checkout_customer_notification_chat_id' => '777',
            'manual_checkout_customer_notification_message_id' => 701,
            'buyer_order_detail_message_chat_id' => '777',
            'buyer_order_detail_message_id' => 702,
        ]);
        Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'status' => PaymentStatus::Paid,
        ]);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);

        app(TelegramBuyerCheckoutInstructionCleaner::class)->deleteTrackedMessages($order);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));

        $order->update(['telegram_receipt_sent_at' => now()]);

        app(TelegramBuyerCheckoutInstructionCleaner::class)->deleteTrackedMessages($order);

        $deleteRequests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/deleteMessage'));
        $this->assertCount(2, $deleteRequests, json_encode($order->fresh()->only([
            'manual_checkout_customer_notification_deleted_at',
            'buyer_order_detail_message_chat_id',
            'buyer_order_detail_message_id',
            'buyer_order_detail_message_deleted_at',
            'buyer_order_detail_message_deletion_started_at',
        ]), JSON_THROW_ON_ERROR));
        $this->assertEqualsCanonicalizing([701, 702], $deleteRequests->map(fn (array $record) => $record[0]['message_id'])->all());
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->manual_checkout_customer_notification_deleted_at);
        $this->assertNotNull($fresh->buyer_order_detail_message_deleted_at);
    }

    public function test_receipt_omits_support_button_without_admin_contact(): void
    {
        config()->set('services.telegram.admin_contact_url', null);
        config()->set('services.telegram.admin_username', null);
        config()->set('services.support.telegram_admin_url', null);
        config()->set('services.support.telegram_admin_username', null);
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 779, 'locale' => 'id']);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '779'
            && count(data_get($r->data(), 'reply_markup.inline_keyboard', [])) === 0
            && ! str_contains((string) $r['text'], '/gpt/panduan-login-akun'));
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
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
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

        $receiptPhotos = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '778'
            && str_contains($request->body(), 'filename="nota-b-'));
        $this->assertCount(1, $receiptPhotos);
        $receiptText = (string) $this->telegramRequestField($receiptPhotos->sole()[0], 'caption');
        $this->assertStringContainsString('email : legacy@example.test', $receiptText);
        $this->assertStringContainsString('password : Password', $receiptText);
        $this->assertStringNotContainsString('legacy@example.test|Password', $receiptText);
        $this->assertStringContainsString('Catatan fallback untuk order lama.', $receiptText);
    }

    public function test_receipt_retry_skips_started_send_after_worker_crash(): void
    {
        $this->fakeTelegramHttp();
        $customer = Customer::factory()->create(['telegram_id' => 777, 'locale' => 'id']);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        $receiptEffects = app(TelegramOutboundEffectService::class);
        $receiptEffect = $receiptEffects->recordReceipt($order);
        app(TelegramOutboundEffectLedgerProtocol::class)->claim($receiptEffect->id);

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
        $order = $this->legacyPakasirOrder($customer->carts()->first());
        $payment = $order->payments()->first();
        $payload = ['project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref, 'amount' => $payment->amount, 'status' => 'completed'];
        $this->fakeTelegramHttp([
            'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload]),
        ]);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();

        $sent = 0;
        Http::assertSent(function ($r) use (&$sent) {
            if (str_ends_with($r->url(), '/sendPhoto')
                && (string) $this->telegramRequestField($r, 'chat_id') === '888'
                && str_contains((string) $this->telegramRequestField($r, 'caption'), 'Pembayaran diterima')) {
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
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        InventoryMovement::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'type' => InventoryMovementType::Sale,
            'quantity' => -1,
        ]);

        return $order;
    }

    private function completedTrackedBinanceOrder(array $overrides = []): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $order = Order::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'manual_checkout_customer_notified_at' => now(),
            'manual_checkout_customer_notification_chat_id' => '12345',
            'manual_checkout_customer_notification_message_id' => 700,
            'manual_checkout_customer_notification_deleted_at' => null,
            'manual_checkout_customer_notification_deletion_started_at' => null,
            'manual_checkout_customer_notification_deletion_token' => null,
        ], $overrides));
        Payment::factory()->create([
            'order_id' => $order->id,
            'provider' => 'manual',
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Paid,
        ]);

        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        if ($receipt !== null) {
            TelegramOutboundEffect::query()
                ->where('effect_group_key', $receipt->effect_group_key)
                ->update([
                    'state' => TelegramOutboundEffectState::Sent->value,
                    'sent_at' => now(),
                    'next_attempt_at' => null,
                ]);
        }

        return $order;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSalesProofTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
