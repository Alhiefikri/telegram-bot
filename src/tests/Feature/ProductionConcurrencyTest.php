<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStock;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\ProductStockItem;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Admin\AdminLoginThrottle;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Admin\PaymentProviderSwitcher;
use App\Services\Binance\BinanceOrderIdActivation;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\ProductStockItemManager;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramUpdateReplayGuard;
use App\Support\PaymentProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
class ProductionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'token' => 'bot-token',
            'webhook_secret' => 'secret',
            'api_base_url' => 'https://api.telegram.org',
            'default_payment_method' => 'BRIVA',
        ]);
        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://pakasir.test',
            'redirect_url' => 'https://example.test/thanks',
            'qris_only' => false,
        ]);
        config()->set('queue.default', 'sync');

        if (! $this->shouldRunConcurrencyLane()) {
            return;
        }

        $this->assertSafeConcurrencyDatabase();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_concurrent_checkout_for_same_stock_allows_only_one_reservation(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'only-secret']);
        $firstCart = $this->cartForProduct($product);
        $secondCart = $this->cartForProduct($product);

        $results = $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($firstCart->id), CheckoutPaymentIntents::manualQris())->id,
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($secondCart->id), CheckoutPaymentIntents::manualQris())->id,
        ]);

        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([InsufficientStock::class], $this->failedResultClasses($results));

        $order = Order::with('payments')->firstOrFail();
        $product->refresh();

        $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(PaymentStatus::Pending, $order->payments->first()->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(1, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(0, PaymentEvent::count());
        $this->assertNull($order->telegram_receipt_sent_at);
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_admin_broadcast_text_workers_share_one_database_cache_window(): void
    {
        $this->assertConcurrentAdminBroadcastWorkersShareOneDatabaseCacheWindow(false);
    }

    public function test_concurrent_admin_broadcast_photo_workers_share_one_database_cache_window(): void
    {
        $this->assertConcurrentAdminBroadcastWorkersShareOneDatabaseCacheWindow(true);
    }

    public function test_concurrent_admin_login_failures_share_one_atomic_database_cache_counter(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseRateLimiter();
        Cache::store('database')->flush();

        $ip = '198.51.100.90';
        $attempts = 12;
        $results = $this->runConcurrently(array_map(
            fn (int $attempt): callable => fn (): int => app(AdminLoginThrottle::class)
                ->recordFailure("concurrent-{$attempt}@example.test", $ip)
                ->ipAttempts,
            range(1, $attempts),
        ));

        $this->assertSame($attempts, $this->successfulResultCount($results), json_encode($results, JSON_THROW_ON_ERROR));

        $observedCounts = array_map(
            fn (array $result): int => (int) ($result['value'] ?? 0),
            $results,
        );
        sort($observedCounts);

        $this->assertSame(range(1, $attempts), $observedCounts);
        $this->assertSame(
            $attempts,
            Cache::store('database')->get(app(AdminLoginThrottle::class)->ipKey($ip)),
        );
    }

    private function assertConcurrentAdminBroadcastWorkersShareOneDatabaseCacheWindow(bool $photo): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        config()->set('services.telegram.broadcast_rate_limit_per_second', 1);
        Cache::flush();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 77]]));

        Customer::factory()->count(2)->sequence(fn ($sequence) => [
            'telegram_id' => 970000 + $sequence->index,
        ])->create();
        $broadcasts = [];
        for ($index = 0; $index < 2; $index++) {
            $broadcasts[] = TelegramBroadcast::create([
                'broadcast_key' => (string) Str::uuid(),
                'source' => 'admin',
                'message' => $photo ? 'Concurrent photo caption' : 'Concurrent text broadcast',
                'photo_file_id' => $photo ? 'bot-owned-photo-reference' : null,
                'status' => TelegramBroadcast::STATUS_QUEUED,
            ]);
        }

        $results = $this->runConcurrently(array_map(
            fn (TelegramBroadcast $broadcast): callable => fn () => app(TelegramBroadcastService::class)
                ->processAdminBroadcastBatch($broadcast->id, 2)
                ->sent,
            $broadcasts,
        ));

        $this->assertSame(2, $this->successfulResultCount($results), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, array_sum(array_map(
            fn (array $result): int => (int) ($result['value'] ?? 0),
            $results,
        )));
        $this->assertSame(1, TelegramBroadcastRecipient::query()->whereNotNull('sent_at')->count());
        $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 1)->count());
        $this->assertSame(3, TelegramBroadcastRecipient::query()->where('attempts', 0)->count());
    }

    public function test_postgres_admin_broadcast_deferral_schedules_next_window_without_claiming_recipient(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        config()->set('services.telegram.broadcast_rate_limit_per_second', 1);
        Cache::flush();
        Queue::fake();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 77]]));

        Customer::factory()->count(2)->sequence(fn ($sequence) => [
            'telegram_id' => 980000 + $sequence->index,
        ])->create();
        $broadcast = TelegramBroadcast::create([
            'broadcast_key' => (string) Str::uuid(),
            'source' => 'admin',
            'message' => 'PostgreSQL deferred batch',
            'status' => TelegramBroadcast::STATUS_QUEUED,
        ]);
        $before = now();

        (new SendTelegramBroadcastBatch($broadcast->id, $before->toISOString()))
            ->handle(app(TelegramBroadcastService::class));

        $this->assertSame(1, TelegramBroadcastRecipient::query()->whereNotNull('sent_at')->count());
        $this->assertSame(1, TelegramBroadcastRecipient::query()->where('attempts', 0)->count());
        Queue::assertPushed(SendTelegramBroadcastBatch::class, function (SendTelegramBroadcastBatch $job) use ($broadcast, $before): bool {
            return $job->broadcastId === $broadcast->id
                && $job->delay instanceof \DateTimeInterface
                && $job->delay->getTimestamp() >= $before->copy()->addSecond()->getTimestamp();
        });
    }

    public function test_concurrent_provider_switch_and_checkout_serialize_on_the_shared_setting_lock(): void
    {
        $this->requireConcurrencyLane();
        KlikQrisSandboxScenarios::configureReadySandbox();

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'payment_provider_version' => 0,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $customer = Customer::factory()->create();
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->setItemQuantity($cart, $product, 1);
        $selection = app(TelegramCheckoutPaymentIntentSelector::class)->select(false);

        $this->assertSame(PaymentProvider::MANUAL, $selection->paymentMode);

        $results = $this->runConcurrently([
            fn () => app(PaymentProviderSwitcher::class)->change(
                provider: PaymentProvider::KLIKQRIS,
                checkoutFlow: null,
                expectedVersion: 0,
                actor: User::findOrFail($owner->id),
                ip: null,
            )['new_provider'],
            fn () => Payment::query()
                ->where('order_id', app(OrderCheckoutService::class)
                    ->checkout(Cart::findOrFail($cart->id), $selection->paymentIntent)
                    ->id)
                ->sole()
                ->provider,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertContains(PaymentProvider::KLIKQRIS, array_map(
            fn (array $result): mixed => $result['value'] ?? null,
            $results,
        ));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(PaymentProvider::KLIKQRIS, ManualPaymentSetting::current()?->paymentProvider());
        $this->assertSame(1, ManualPaymentSetting::current()?->payment_provider_version);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment_provider.switched')->count());
    }

    public function test_concurrent_binance_checkout_for_same_stock_allows_only_one_reservation(): void
    {
        $this->requireConcurrencyLane();
        config()->set('services.binance.pay_id', '123456789');
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 2_000_000]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'only-binance-secret']);
        $firstCart = $this->cartForProduct($product);
        $secondCart = $this->cartForProduct($product);

        $results = $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($firstCart->id), CheckoutPaymentIntents::binancePay())->id,
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($secondCart->id), CheckoutPaymentIntents::binancePay())->id,
        ]);

        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([InsufficientStock::class], $this->failedResultClasses($results));
        $payment = Payment::sole();
        $product->refresh();

        $this->assertSame('BINANCE_PAY_ID', $payment->method);
        $this->assertSame('USDT', $payment->currency);
        $this->assertSame(2_000_000, $payment->amount);
        $this->assertSame(1, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_binance_auto_verification_checkout_allows_same_exact_amount(): void
    {
        $this->requireConcurrencyLane();
        config()->set('services.binance', [
            'pay_id' => '123456789',
            'api_key' => 'read-only-key',
            'api_secret' => 'read-only-secret',
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        app(BinanceOrderIdActivation::class)->activate();

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 2_000_000]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'first-binance-secret']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'second-binance-secret']);
        $firstCart = $this->cartForProduct($product);
        $secondCart = $this->cartForProduct($product);

        $results = $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($firstCart->id), CheckoutPaymentIntents::binancePay())->id,
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($secondCart->id), CheckoutPaymentIntents::binancePay())->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertSame(2, Payment::query()->where('binance_auto_verification_enabled', true)->count());
        $this->assertSame(2, Payment::query()->where('amount', 2_000_000)->count());
    }

    public function test_concurrent_binance_verification_claims_one_transaction_once(): void
    {
        $this->requireConcurrencyLane();
        config()->set('services.telegram.token', '');
        config()->set('services.binance', [
            'pay_id' => '123456789',
            'api_key' => 'read-only-key',
            'api_secret' => 'read-only-secret',
            'base_url' => 'https://api.binance.test',
            'history_max_attempts' => 1,
            'history_retry_delay_ms' => 0,
            'history_cache_seconds' => 0,
            'history_lock_seconds' => 1,
            'verify_clock_skew_seconds' => 0,
        ]);
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'binance_pay_enabled' => true,
            'is_active' => true,
            'binance_verification_mode' => 'order_id',
            'binance_order_id_compatibility_ready_at' => now(),
        ]);
        app(BinanceOrderIdActivation::class)->activate();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'usdt_price_amount' => 2_000_000]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'binance-race-secret']);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::binancePay());
        $payment = $order->payments()->sole();
        $providerOrderId = '445077777777777777';
        $payment->update([
            'binance_order_id' => $providerOrderId,
            'binance_order_id_attempts' => 1,
            'binance_order_id_submitted_at' => now(),
            'binance_verification_grace_until' => now()->addMinutes(3),
        ]);
        Http::fake([
            'https://api.binance.test/*' => Http::response([
                'code' => '000000',
                'message' => 'success',
                'success' => true,
                'data' => [[
                    'orderId' => $providerOrderId,
                    'transactionId' => 'P_777777777777',
                    'amount' => '2',
                    'currency' => 'USDT',
                    'transactionTime' => now()->getTimestampMs(),
                    'receiverInfo' => ['accountId' => '123456789'],
                ]],
            ]),
        ]);

        $results = $this->runConcurrently([
            fn () => app(BinancePaymentVerification::class)->verify(Payment::findOrFail($payment->id), null, 0)->outcome->value,
            fn () => app(BinancePaymentVerification::class)->verify(Payment::findOrFail($payment->id), null, 0)->outcome->value,
        ]);

        $outcomes = array_map(fn (array $result): mixed => $result['value'] ?? null, $results);
        sort($outcomes);
        $this->assertSame(['already_paid', 'confirmed'], $outcomes);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, PaymentEvent::where('provider', 'binance')->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_concurrent_cart_quantity_allows_each_cart_to_hold_last_stock_until_checkout(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cart-secret']);
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(CartService::class)->setItemQuantity(app(CartService::class)->activeCartFor(Customer::findOrFail($first->id)), Product::findOrFail($product->id), 1)?->id,
            fn () => app(CartService::class)->setItemQuantity(app(CartService::class)->activeCartFor(Customer::findOrFail($second->id)), Product::findOrFail($product->id), 1)?->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertSame(2, $this->activeCartQuantityFor($product));
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_cart_quantity_tracks_each_cart_without_mutating_inventory(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cart-secret-a']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'cart-secret-b']);
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(CartService::class)->setItemQuantity(app(CartService::class)->activeCartFor(Customer::findOrFail($first->id)), Product::findOrFail($product->id), 2)?->id,
            fn () => app(CartService::class)->setItemQuantity(app(CartService::class)->activeCartFor(Customer::findOrFail($second->id)), Product::findOrFail($product->id), 1)?->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertSame(3, $this->activeCartQuantityFor($product));
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_duplicate_paid_callbacks_create_one_sale_and_one_receipt(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $order = $this->pakasirReadyOrder(stock: 2, quantity: 1, expiresAt: now()->addHour());
        $payment = $order->payments()->firstOrFail();
        $payload = $this->pakasirPayload($payment);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true]), 'https://pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload])]);

        $results = $this->runConcurrently([
            fn () => $this->processPakasirCallback($payload),
            fn () => $this->processPakasirCallback($payload),
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));

        $order->refresh();
        $payment->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(OrderStatus::Completed, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $processedCallbackEvent = PaymentEvent::where('event_type', PaymentEventType::Callback)->whereNotNull('processed_at')->sole();
        $this->assertGreaterThanOrEqual(2, $processedCallbackEvent->received_count);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_paid_callback_racing_expiry_preserves_single_valid_inventory_outcome(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $order = $this->pakasirReadyOrder(stock: 1, quantity: 1, expiresAt: now()->subMinute());
        $payment = $order->payments()->firstOrFail();
        $payload = $this->pakasirPayload($payment);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true]), 'https://pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload])]);

        $this->runConcurrently([
            fn () => $this->processPakasirCallback($payload),
            fn () => app(OrderStateMachine::class)->expirePendingOrder(Order::findOrFail($order->id))?->id,
        ]);

        $order->refresh();
        $payment->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(1, PaymentEvent::where('event_type', PaymentEventType::Callback)->whereNotNull('processed_at')->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());

        if ($order->order_status === OrderStatus::Completed) {
            $this->assertSame(0, $product->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
            $this->assertNotNull($order->telegram_receipt_sent_at);

            return;
        }

        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertNull($order->telegram_receipt_sent_at);
    }

    public function test_native_qris_creation_racing_expiry_never_persists_a_payable_qr_on_an_expired_order(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        KlikQrisSandboxScenarios::configureReadySandbox();
        app()->bind(KlikQrisProviderAdapter::class, fn () => new ProductionConcurrencyKlikQrisAdapter);

        $order = $this->klikQrisReadyOrder(now()->addHour());
        $order->update(['expires_at' => now()->subSecond()]);
        $payment = $order->payments()->sole();
        $payment->update(['expired_at' => now()->subSecond()]);

        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
            fn () => app(OrderStateMachine::class)->expirePendingOrder(Order::findOrFail($order->id))?->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame(0, $payment->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate)->count());
        $this->assertFalse($payment->isPayableNativeKlikQris());
    }

    public function test_native_qris_creation_racing_cancellation_never_revives_a_cancelled_order(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        KlikQrisSandboxScenarios::configureReadySandbox();
        app()->bind(KlikQrisProviderAdapter::class, fn () => new ProductionConcurrencyKlikQrisAdapter);

        $order = $this->klikQrisReadyOrder(now()->addHour());
        $payment = $order->payments()->sole();

        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
            fn () => app(OrderStateMachine::class)->cancelPendingOrder(Order::findOrFail($order->id))->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertFalse($payment->isPayableNativeKlikQris());
    }

    public function test_klikqris_callback_verification_racing_cancellation_has_one_valid_lifecycle_outcome(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        KlikQrisSandboxScenarios::configureReadySandbox();
        app()->bind(KlikQrisProviderAdapter::class, fn () => new ProductionConcurrencyKlikQrisAdapter(successfulStatus: true));

        $order = $this->klikQrisReadyOrder(now()->addHour());
        $payment = $order->payments()->sole();
        $payment->update([
            'provider_total_payment' => $payment->amount + 16,
            'provider_payment_method' => 'qris',
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment),
            'provider_create_state' => 'created',
        ]);
        $payload = [
            'order_id' => $payment->merchant_ref,
            'amount' => (string) $payment->amount,
            'total_amount' => (string) ($payment->amount + 16),
            'status' => 'PAID',
            'signature' => 'ignored-signature',
        ];

        $results = $this->runConcurrently([
            fn () => app(KlikQrisCallbackProcessor::class)->processCallback($payload)->id,
            fn () => app(OrderStateMachine::class)->cancelPendingOrder(Order::findOrFail($order->id))->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $order->refresh();
        $payment->refresh();
        $sales = InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count();
        $releases = InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Release)->count();

        if ($order->order_status === OrderStatus::Cancelled) {
            $this->assertSame(PaymentStatus::Paid, $payment->status);
            $this->assertSame(PaymentStatus::Paid, $order->payment_status);
            $this->assertSame(0, $sales);
            $this->assertSame(1, $releases);
            $this->assertDatabaseCount('manual_fulfillment_reviews', 1);

            return;
        }

        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(1, $sales);
        $this->assertSame(0, $releases);
    }

    public function test_klikqris_reconciliation_racing_native_qris_creation_keeps_one_provider_create_event(): void
    {
        $this->requireConcurrencyLane();
        $this->useDatabaseLocks();
        KlikQrisSandboxScenarios::configureReadySandbox();
        app()->bind(KlikQrisProviderAdapter::class, fn () => new ProductionConcurrencyKlikQrisAdapter);

        $order = $this->klikQrisReadyOrder(now()->addHour());
        $payment = $order->payments()->sole();

        $results = $this->runConcurrently([
            fn () => app(KlikQrisNativeQrisService::class)->reconcile(
                Payment::findOrFail($payment->id),
                fn (Payment $payment, KlikQrisTransaction $transaction): null => null,
            )?->outcome()->value,
            fn () => app(KlikQrisNativeQrisService::class)->lifecycle(Payment::findOrFail($payment->id))->outcome()->value,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $payment->refresh();
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate)->count());
        $this->assertTrue($payment->isPayableNativeKlikQris());
    }

    public function test_duplicate_durable_notification_recipient_claims_send_once(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $customer = Customer::factory()->create(['telegram_id' => '901233']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'is_active' => true]);
        $event = ProductPriceChangeEvent::create([
            'event_key' => (string) Str::uuid(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'old_price_amount' => 10000,
            'new_price_amount' => 11000,
        ]);
        $event->update(['recipients_materialized_at' => now()]);
        $recipient = ProductPriceChangeNotificationRecipient::create([
            'event_id' => $event->id,
            'customer_id' => $customer->id,
            'telegram_id' => $customer->telegram_id,
            'locale' => 'id',
        ]);

        $results = $this->runConcurrently([
            fn () => app(ProductPriceChangeNotificationService::class)->processBatch($event->id, 1)->processed,
            fn () => app(ProductPriceChangeNotificationService::class)->processBatch($event->id, 1)->processed,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $recipient->refresh();
        $this->assertNotNull($recipient->sent_at);
        $this->assertSame(1, $recipient->attempts);
    }

    public function test_admin_manual_completion_racing_expiry_preserves_single_valid_inventory_outcome(): void
    {
        $this->requireConcurrencyLane();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->create();
        $order = $this->manualReadyOrder(stock: 1, quantity: 1, expiresAt: now()->subMinute());

        $this->runConcurrently([
            fn () => app(AdminOrderOperations::class)->completeManualOrder(Order::findOrFail($order->id), User::findOrFail($user->id), '127.0.0.1')->id,
            fn () => app(OrderStateMachine::class)->expirePendingOrder(Order::findOrFail($order->id))?->id,
        ]);

        $order->refresh();
        $payment = $order->payments()->firstOrFail();
        $product = Product::firstOrFail();

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(0, PaymentEvent::count());

        if ($order->order_status === OrderStatus::Completed) {
            $this->assertSame(PaymentStatus::Paid, $order->payment_status);
            $this->assertSame(PaymentStatus::Paid, $payment->status);
            $this->assertSame(0, $product->stock);
            $this->assertSame(0, $product->reserved_stock);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
            $this->assertNotNull($order->telegram_receipt_sent_at);

            return;
        }

        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payment_status);
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame(1, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertNull($order->telegram_receipt_sent_at);
    }

    public function test_concurrent_admin_add_stock_preserves_all_submitted_rows(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $user = User::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(StockAdjustmentService::class)->adjust(Product::findOrFail($product->id), 2, 'first add', User::findOrFail($user->id), '127.0.0.1', ['a-1', 'a-2'])->id,
            fn () => app(StockAdjustmentService::class)->adjust(Product::findOrFail($product->id), 2, 'second add', User::findOrFail($user->id), '127.0.0.1', ['b-1', 'b-2'])->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $product->refresh();
        $this->assertSame(4, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(4, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(4, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_admin_delete_different_stock_items_decrements_once_per_item(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $first = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-a']);
        $second = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-b']);
        $user = User::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(ProductStockItemManager::class)->deleteAvailable(Product::findOrFail($product->id), ProductStockItem::findOrFail($first->id), User::findOrFail($user->id), '127.0.0.1')->id,
            fn () => app(ProductStockItemManager::class)->deleteAvailable(Product::findOrFail($product->id), ProductStockItem::findOrFail($second->id), User::findOrFail($user->id), '127.0.0.1')->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $product->refresh();
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(-2, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->sum('quantity'));
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_admin_delete_same_stock_item_decrements_once(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-once']);
        $user = User::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(ProductStockItemManager::class)->deleteAvailable(Product::findOrFail($product->id), ProductStockItem::findOrFail($item->id), User::findOrFail($user->id), '127.0.0.1')->id,
            fn () => app(ProductStockItemManager::class)->deleteAvailable(Product::findOrFail($product->id), ProductStockItem::findOrFail($item->id), User::findOrFail($user->id), '127.0.0.1')->id,
        ]);

        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertSame([ModelNotFoundException::class], $this->failedResultClasses($results));
        $product->refresh();
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Adjustment)->count());
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_checkout_claim_racing_admin_delete_has_single_winning_mutation(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'race-secret']);
        $cart = $this->cartForProduct($product);
        $user = User::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cart->id), CheckoutPaymentIntents::manualQris())->id,
            fn () => app(ProductStockItemManager::class)->deleteAvailable(Product::findOrFail($product->id), ProductStockItem::findOrFail($item->id), User::findOrFail($user->id), '127.0.0.1')->id,
        ]);

        $this->assertSame(1, $this->successfulResultCount($results));
        $this->assertContains($this->failedResultClasses($results)[0] ?? null, [InsufficientStock::class, ModelNotFoundException::class]);
        $product->refresh();
        $this->assertGreaterThanOrEqual(0, $product->stock);
        $this->assertGreaterThanOrEqual(0, $product->reserved_stock);
        $this->assertGreaterThanOrEqual($product->reserved_stock, $product->stock);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->whereIn('type', [InventoryMovementType::Reservation, InventoryMovementType::Adjustment])->count());
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_checkout_claim_racing_admin_edit_serializes_secret(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'old-secret']);
        $cart = $this->cartForProduct($product);
        $user = User::factory()->create();

        $this->runConcurrently([
            fn () => app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cart->id), CheckoutPaymentIntents::manualQris())->id,
            fn () => app(ProductStockItemManager::class)->updateAvailableSecret(Product::findOrFail($product->id), ProductStockItem::findOrFail($item->id), 'new-secret', User::findOrFail($user->id), '127.0.0.1')->id,
        ]);

        $product->refresh();
        $claimed = ProductStockItem::where('product_id', $product->id)->firstOrFail();
        $this->assertSame(1, $product->stock);
        $this->assertSame(1, $product->reserved_stock);
        $this->assertNotNull($claimed->order_item_id);
        $this->assertContains($claimed->secret, ['old-secret', 'new-secret']);
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Reservation)->count());
        $this->assertLessThanOrEqual(1, AuditLog::where('action', 'product_stock_item.updated')->where('subject_id', (string) $item->id)->count());
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_order_release_racing_admin_stock_add_preserves_stock_items(): void
    {
        $this->requireConcurrencyLane();

        $order = $this->credentialReadyOrder(stock: 1, quantity: 1, expiresAt: now()->addHour());
        $product = Product::firstOrFail();
        $user = User::factory()->create();

        $results = $this->runConcurrently([
            fn () => app(OrderStateMachine::class)->cancelPendingOrder(Order::findOrFail($order->id))->id,
            fn () => app(StockAdjustmentService::class)->adjust(Product::findOrFail($product->id), 1, 'release race add', User::findOrFail($user->id), '127.0.0.1', ['new-secret'])->id,
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $product->refresh();
        $this->assertSame(2, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->where('type', InventoryMovementType::Release)->count());
        $this->assertManagedInventoryInvariants($product);
    }

    public function test_concurrent_product_price_updates_create_sequential_price_change_events(): void
    {
        $this->requireConcurrencyLane();

        $product = Product::factory()->create([
            'name' => 'Sequential Price Product',
            'price_amount' => 10000,
            'usdt_price_amount' => 1_000_000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $results = $this->runConcurrently([
            fn () => $this->lockedPriceUpdate($product->id, 20000, 2_000_000),
            fn () => $this->lockedPriceUpdate($product->id, 30000, 3_000_000),
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));

        $events = ProductPriceChangeEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(10000, $events[0]->old_price_amount);
        $this->assertContains($events[0]->new_price_amount, [20000, 30000]);
        $this->assertSame($events[0]->new_price_amount, $events[1]->old_price_amount);
        $this->assertContains($events[1]->new_price_amount, [20000, 30000]);
        $this->assertNotSame($events[0]->new_price_amount, $events[1]->new_price_amount);
        $this->assertSame($events[1]->new_price_amount, $product->fresh()->price_amount);
    }

    public function test_concurrent_duplicate_telegram_update_workers_run_side_effect_once(): void
    {
        $this->requireConcurrencyLane();

        $update = TelegramUpdate::create([
            'update_id' => 990001,
            'payload' => [
                'update_id' => 990001,
                'message' => [
                    'message_id' => 1,
                    'text' => '/start',
                    'from' => ['id' => 990001, 'first_name' => 'Concurrent'],
                    'chat' => ['id' => 990001, 'type' => 'private'],
                ],
            ],
            'created_at' => now(),
        ]);

        $results = $this->runConcurrently([
            fn () => $this->claimTelegramUpdateWithAuditSideEffect($update->id),
            fn () => $this->claimTelegramUpdateWithAuditSideEffect($update->id),
        ]);

        $this->assertSame(2, $this->successfulResultCount($results));
        $this->assertSame([], $this->failedResultClasses($results));
        $this->assertNotNull($update->fresh()->processed_at);
        $this->assertNull($update->fresh()->processing_started_at);
        $this->assertSame(1, AuditLog::where('action', 'telegram_update.concurrent_side_effect')->where('subject_id', (string) $update->id)->count());
    }

    private function requireConcurrencyLane(): void
    {
        if (! $this->shouldRunConcurrencyLane()) {
            $this->markTestSkipped('Set RUN_CONCURRENCY_TESTS=1 with a PostgreSQL test database to run production-like concurrency tests.');
        }

        $this->assertSafeConcurrencyDatabase();
    }

    private function shouldRunConcurrencyLane(): bool
    {
        return (string) env('RUN_CONCURRENCY_TESTS', '') === '1';
    }

    private function assertSafeConcurrencyDatabase(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for process-level concurrency tests.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for PostgreSQL concurrency tests.');
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Production-like concurrency tests require DB_CONNECTION=pgsql.');
        }

        $database = strtolower((string) config('database.connections.pgsql.database'));
        if (! str_contains($database, 'test')) {
            $this->fail('Refusing to run concurrency tests unless the PostgreSQL database name contains "test".');
        }
    }

    /**
     * @param  list<callable(): mixed>  $callbacks
     * @return list<array{ok: bool, value?: mixed, class?: string, message?: string, exit_code: int}>
     */
    private function runConcurrently(array $callbacks): array
    {
        $runId = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir().'/telegram-bot-concurrency-'.$runId;

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            $this->fail('Unable to create concurrency test workspace.');
        }

        $releaseFile = $directory.'/release';
        $children = [];

        DB::disconnect();

        foreach ($callbacks as $index => $callback) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork concurrency test process.');
            }

            if ($pid === 0) {
                $this->runChildProcess($callback, $releaseFile, $directory.'/result-'.$index.'.json');
            }

            $children[$index] = $pid;
        }

        touch($releaseFile);

        $results = [];
        foreach ($children as $index => $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);

            $path = $directory.'/result-'.$index.'.json';
            $result = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [
                'ok' => false,
                'class' => 'RuntimeException',
                'message' => 'Child process did not write a result file.',
            ];
            $result['exit_code'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
            $results[] = $result;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);

        DB::reconnect();

        return $results;
    }

    private function lockedPriceUpdate(int $productId, int $idrPrice, int $usdtPrice): int
    {
        return DB::transaction(function () use ($productId, $idrPrice, $usdtPrice): int {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $oldValues = $product->only(['price_amount', 'usdt_price_amount']);
            $product->update([
                'price_amount' => $idrPrice,
                'usdt_price_amount' => $usdtPrice,
            ]);
            $newValues = $product->only(['price_amount', 'usdt_price_amount']);

            return app(ProductPriceChangeNotificationService::class)
                ->createEventForCommittedUpdate($product, $oldValues, $newValues)
                ?->id ?? 0;
        }, 5);
    }

    private function runChildProcess(callable $callback, string $releaseFile, string $resultFile): never
    {
        DB::disconnect();

        $deadline = microtime(true) + 10;
        while (! is_file($releaseFile)) {
            if (microtime(true) > $deadline) {
                file_put_contents($resultFile, json_encode([
                    'ok' => false,
                    'class' => 'RuntimeException',
                    'message' => 'Timed out waiting for concurrency barrier.',
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }

            usleep(10_000);
        }

        try {
            $value = $callback();
            file_put_contents($resultFile, json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($resultFile, json_encode([
                'ok' => false,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }

    private function claimTelegramUpdateWithAuditSideEffect(int $updateId): int
    {
        app(TelegramUpdateReplayGuard::class)->processOnce(
            TelegramUpdate::findOrFail($updateId),
            function (TelegramUpdate $update): void {
                usleep(150_000);
                AuditLog::create([
                    'action' => 'telegram_update.concurrent_side_effect',
                    'subject_type' => TelegramUpdate::class,
                    'subject_id' => (string) $update->id,
                    'new_values' => ['update_id' => $update->update_id],
                    'created_at' => now(),
                ]);
            }
        );

        return AuditLog::where('action', 'telegram_update.concurrent_side_effect')->where('subject_id', (string) $updateId)->count();
    }

    private function successfulResultCount(array $results): int
    {
        return count(array_filter($results, fn (array $result): bool => $result['ok'] && $result['exit_code'] === 0));
    }

    /** @return list<class-string> */
    private function failedResultClasses(array $results): array
    {
        $classes = array_values(array_map(
            fn (array $result): string => (string) ($result['class'] ?? ''),
            array_filter($results, fn (array $result): bool => ! $result['ok'] || $result['exit_code'] !== 0),
        ));
        sort($classes);

        return $classes;
    }

    private function activeCartQuantityFor(Product $product): int
    {
        return (int) CartItem::query()
            ->join('carts', 'cart_items.cart_id', '=', 'carts.id')
            ->where('cart_items.product_id', $product->id)
            ->where('carts.status', CartStatus::Active->value)
            ->where(fn ($query) => $query
                ->whereNull('carts.expires_at')
                ->orWhere('carts.expires_at', '>', now()))
            ->sum('cart_items.quantity');
    }

    private function assertManagedInventoryInvariants(Product $product): void
    {
        $product = $product->fresh();
        $this->assertNotNull($product);
        $numericAvailable = max(0, $product->stock - $product->reserved_stock);

        $this->assertGreaterThanOrEqual(0, $product->stock);
        $this->assertGreaterThanOrEqual(0, $product->reserved_stock);
        $this->assertGreaterThanOrEqual($product->reserved_stock, $product->stock);

        $stockItems = ProductStockItem::where('product_id', $product->id)->get();
        $unclaimed = $stockItems->whereNull('order_item_id');
        $claimed = $stockItems->whereNotNull('order_item_id');

        $this->assertSame(min($numericAvailable, $unclaimed->count()), $product->availableStock());
        $this->assertSame($product->reserved_stock, $claimed->count());
        foreach ($claimed as $item) {
            $this->assertNotNull($item->order_item_id);
            $this->assertTrue(OrderItem::whereKey($item->order_item_id)->exists());
        }
        foreach ($unclaimed as $item) {
            $this->assertNull($item->order_item_id);
        }

        $claimedSecrets = $claimed->pluck('secret')->all();
        $this->assertSame(count($claimedSecrets), count(array_unique($claimedSecrets)));
    }

    private function cartForProduct(Product $product, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(100000, 999999999)]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }

    private function credentialReadyOrder(int $stock, int $quantity, mixed $expiresAt): Order
    {
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        for ($i = 1; $i <= $stock; $i++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'secret-'.$i]);
        }
        $cart = $this->cartForProduct($product, $quantity);

        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris($expiresAt));
    }

    private function manualReadyOrder(int $stock, int $quantity, mixed $expiresAt): Order
    {
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = $this->cartForProduct($product, $quantity);

        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris($expiresAt));
    }

    private function klikQrisReadyOrder(mixed $expiresAt): Order
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = $this->cartForProduct($product);

        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::klikQris($expiresAt));
    }

    private function useDatabaseLocks(): void
    {
        config()->set('cache.default', 'database');
        Cache::purge('database');
    }

    private function useDatabaseRateLimiter(): void
    {
        config()->set('cache.default', 'database');
        Cache::purge('database');
        app()->forgetInstance(RateLimiter::class);
    }

    private function pakasirReadyOrder(int $stock, int $quantity, mixed $expiresAt): Order
    {
        $order = $this->manualReadyOrder($stock, $quantity, $expiresAt);
        $order->payments()->firstOrFail()->update(['provider' => 'pakasir', 'method' => 'hosted']);

        return $order->fresh(['payments']);
    }

    private function pakasirPayload(Payment $payment): array
    {
        return [
            'project' => 'warung-ai',
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'status' => 'completed',
        ];
    }

    private function processPakasirCallback(array $payload): int
    {
        $response = $this->postJson('/api/webhook/pakasir', $payload);

        $response->assertOk();

        return $response->getStatusCode();
    }
}

final class ProductionConcurrencyKlikQrisAdapter implements KlikQrisProviderAdapter
{
    public function __construct(private readonly bool $successfulStatus = false) {}

    public function createTransaction(Payment $payment): KlikQrisTransaction
    {
        usleep(150_000);

        return KlikQrisTransaction::fromCreatePayload(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]));
    }

    public function transactionStatus(Payment $payment): KlikQrisTransaction
    {
        usleep(150_000);

        if ($this->successfulStatus) {
            return KlikQrisTransaction::fromStatusPayload(KlikQrisSandboxScenarios::statusPayload($payment));
        }

        return KlikQrisTransaction::fromStatusPayload(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]));
    }
}
