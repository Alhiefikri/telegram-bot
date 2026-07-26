<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\DeleteAdminPendingOrderNotification;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Checkout\CheckoutPostCreationBehavior;
use App\Services\InventoryLedger;
use App\Services\InvoiceNumberGenerator;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleResult;
use App\Services\OrderStateMachine;
use App\Support\PakasirCheckoutFlow;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PakasirNativeQrisScenarios;
use Tests\TestCase;

class Phase4OrderCartDomainTest extends TestCase
{
    use PakasirNativeQrisScenarios;
    use RefreshDatabase;

    public function test_duplicate_checkout_returns_existing_order(): void
    {
        $cart = $this->cartWithProduct(stock: 2);
        $service = app(OrderCheckoutService::class);

        $order = $service->checkout($cart);
        $again = $service->checkout($cart->fresh());

        $this->assertTrue($order->is($again));
        $this->assertSame(1, Order::where('cart_id', $cart->id)->count());
    }

    public function test_binance_checkout_persists_usdt_payment_currency(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create(['binance_pay_enabled' => true, 'is_active' => true]);
        $product = Product::factory()->create([
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product), CheckoutPaymentIntents::binancePay());

        $this->assertSame('USDT', $order->payments()->sole()->currency);
        $this->assertSame(2_500_000, $order->payments()->sole()->amount);
    }

    public function test_checkout_payment_intents_own_provider_amount_currency_deadline_and_post_creation_behavior(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        config()->set('services.pakasir.slug', 'lhie-store');
        config()->set('services.pakasir.api_key', 'pakasir-key');
        config()->set('services.pakasir.base_url', 'https://app.pakasir.test');
        ManualPaymentSetting::create([
            'binance_pay_enabled' => true,
            'qris_payment_ttl_minutes' => 9,
            'binance_payment_ttl_minutes' => 19,
            'is_active' => true,
        ]);

        $pakasirIntent = CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::API_QRIS);
        $pakasirOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), $pakasirIntent);
        $pakasirPayment = $pakasirOrder->payments()->sole();

        $this->assertSame('pakasir', $pakasirPayment->provider);
        $this->assertSame(PakasirCheckoutFlow::API_QRIS, $pakasirPayment->method);
        $this->assertSame('IDR', $pakasirPayment->currency);
        $this->assertSame($pakasirOrder->total_amount, $pakasirPayment->amount);
        $this->assertTrue($pakasirPayment->expired_at->between(now()->addMinutes(8), now()->addMinutes(10)));
        $this->assertSame(CheckoutPostCreationBehavior::PakasirInstructions, $pakasirIntent->postCreationBehavior());

        $binanceIntent = CheckoutPaymentIntents::binancePay();
        $binanceProduct = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 3_250_000]);
        $binanceOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($binanceProduct, quantity: 2), $binanceIntent);
        $binancePayment = $binanceOrder->payments()->sole();

        $this->assertSame('manual', $binancePayment->provider);
        $this->assertSame('BINANCE_PAY_ID', $binancePayment->method);
        $this->assertSame('USDT', $binancePayment->currency);
        $this->assertSame(6_500_000, $binancePayment->amount);
        $this->assertTrue($binancePayment->expired_at->between(now()->addMinutes(18), now()->addMinutes(20)));
        $this->assertSame(CheckoutPostCreationBehavior::BinancePayInstructions, $binanceIntent->postCreationBehavior());
    }

    public function test_unready_checkout_payment_intent_fails_before_order_payment_or_stock_reservation(): void
    {
        ManualPaymentSetting::create(['binance_pay_enabled' => false, 'is_active' => true]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0, 'usdt_price_amount' => 2_500_000]);

        try {
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product), CheckoutPaymentIntents::binancePay());
            $this->fail('Expected unready Binance Pay rail to fail.');
        } catch (InvalidCheckout $exception) {
            $this->assertSame('Binance Pay is not ready.', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, ProductStockItem::query()->whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_unready_pakasir_intent_fails_before_order_payment_or_stock_reservation(): void
    {
        config()->set('services.pakasir.slug', null);
        config()->set('services.pakasir.api_key', null);
        ManualPaymentSetting::create([
            'payment_mode' => 'pakasir',
            'pakasir_checkout_flow' => PakasirCheckoutFlow::API_QRIS,
            'is_active' => true,
        ]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);

        try {
            app(OrderCheckoutService::class)->checkout(
                $this->cartWithProduct($product),
                CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::API_QRIS),
            );
            $this->fail('Expected unready Pakasir rail to fail.');
        } catch (InvalidCheckout $exception) {
            $this->assertStringStartsWith('Pakasir payment rail is not ready.', $exception->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, ProductStockItem::query()->whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_checkout_uses_configured_deadline_for_each_payment_method(): void
    {
        ManualPaymentSetting::create([
            'qris_payment_ttl_minutes' => 7,
            'binance_payment_ttl_minutes' => 25,
            'binance_pay_enabled' => true,
            'is_active' => true,
        ]);
        config()->set('services.binance.pay_id', '987654321');

        $qrisOrder = app(OrderCheckoutService::class)->checkout(
            $this->cartWithProduct(stock: 2),
            CheckoutPaymentIntents::manualQris(),
        );
        $binanceOrder = app(OrderCheckoutService::class)->checkout(
            $this->cartWithProduct(Product::factory()->create([
                'stock' => 2,
                'reserved_stock' => 0,
                'usdt_price_amount' => 2_500_000,
            ])),
            CheckoutPaymentIntents::binancePay(),
        );

        $this->assertTrue($qrisOrder->expires_at->between(now()->addMinutes(6), now()->addMinutes(8)));
        $this->assertTrue($qrisOrder->payments()->sole()->expired_at->between(now()->addMinutes(6), now()->addMinutes(8)));
        $this->assertTrue($binanceOrder->expires_at->between(now()->addMinutes(24), now()->addMinutes(26)));
        $this->assertTrue($binanceOrder->payments()->sole()->expired_at->between(now()->addMinutes(24), now()->addMinutes(26)));
    }

    public function test_two_carts_stock_one_only_first_checkout_succeeds(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $first = $this->cartWithProduct($product);
        $second = $this->cartWithProduct($product);

        app(OrderCheckoutService::class)->checkout($first);

        $this->expectException(InsufficientStock::class);
        app(OrderCheckoutService::class)->checkout($second);
    }

    public function test_paid_once_moves_to_processing_and_preserves_stock_on_retry(): void
    {
        $cart = $this->cartWithProduct(stock: 3, quantity: 2);
        $order = app(OrderCheckoutService::class)->checkout($cart);
        $payment = $order->payments()->first();

        $first = app(OrderStateMachine::class)->applyPaymentPaid($payment);
        $replay = app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $this->assertTrue($first->newlyApplied);
        $this->assertFalse($replay->newlyApplied);
        $this->assertSame(OrderStatus::Processing, $first->orderStatus());
        $this->assertSame(PaymentStatus::Paid, $first->paymentStatus());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, Product::first()->stock);
        $this->assertSame(0, Product::first()->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_paid_order_dispatches_admin_pending_notification_delete(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2));

        app(OrderStateMachine::class)->markPaymentPaid($order->payments()->firstOrFail());

        Queue::assertPushedOn('notifications', DeleteAdminPendingOrderNotification::class, fn (DeleteAdminPendingOrderNotification $job): bool => $job->orderId === $order->id);
    }

    public function test_expiry_and_cancel_release_once(): void
    {
        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartWithProduct(stock: 2),
            CheckoutPaymentIntents::manualQris(now()->subMinute()),
        );

        $expired = app(OrderStateMachine::class)->applyPendingExpiry($order);
        $expiredReplay = app(OrderStateMachine::class)->applyPendingExpiry($order->fresh());

        $this->assertTrue($expired->newlyApplied);
        $this->assertFalse($expiredReplay->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $expired->orderStatus());
        $this->assertSame(PaymentStatus::Expired, $expired->paymentStatus());
        $this->assertSame(0, Product::first()->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());

        $order2 = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2));
        $cancelled = app(OrderStateMachine::class)->applyPendingCancellation($order2);
        $cancelReplay = app(OrderStateMachine::class)->applyPendingCancellation($order2->fresh());
        $this->assertTrue($cancelled->newlyApplied);
        $this->assertFalse($cancelReplay->newlyApplied);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->orderStatus());
        $this->assertSame(PaymentStatus::Failed, $cancelled->paymentStatus());
        $this->assertSame(2, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    public function test_expiring_pending_order_dispatches_private_admin_expired_notification_once(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinute()));

        app(OrderStateMachine::class)->expirePendingOrder($order);
        app(OrderStateMachine::class)->expirePendingOrder($order->fresh());

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payments()->firstOrFail()->fresh()->status);
        Queue::assertPushedOn('notifications', DeleteAdminPendingOrderNotification::class, fn (DeleteAdminPendingOrderNotification $job): bool => $job->orderId === $order->id);
        Queue::assertPushedOn('notifications', SendAdminOrderExpiredNotification::class, fn (SendAdminOrderExpiredNotification $job): bool => $job->orderId === $order->id);
        Queue::assertPushed(SendAdminOrderExpiredNotification::class, 1);
    }

    public function test_cancel_pending_order_does_not_dispatch_private_admin_expired_notification(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2));

        app(OrderStateMachine::class)->cancelPendingOrder($order);

        Queue::assertNotPushed(SendAdminOrderExpiredNotification::class);
        Queue::assertPushedOn('notifications', DeleteAdminPendingOrderNotification::class, fn (DeleteAdminPendingOrderNotification $job): bool => $job->orderId === $order->id);
    }

    public function test_default_pending_payment_ttl_is_ten_minutes_and_expiry_releases_credential(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'ttl@example.test|pass']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product));

        $this->assertTrue($order->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        $this->assertNotNull($stockItem->fresh()->order_item_id);
        $order->forceFill(['expires_at' => now()->subMinute()])->save();
        app(OrderStateMachine::class)->expirePendingOrder($order->fresh());

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(1, Product::first()->availableStock());
        $this->assertNull($stockItem->fresh()->order_item_id);
    }

    public function test_expiry_command_releases_stock_when_configured_qris_deadline_is_due(): void
    {
        config(['services.telegram.token' => null]);
        $startedAt = now()->startOfMinute();
        Carbon::setTestNow($startedAt);

        try {
            ManualPaymentSetting::create([
                'qris_payment_ttl_minutes' => 1,
                'binance_payment_ttl_minutes' => 20,
                'is_active' => true,
            ]);
            $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
            $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'configured-expiry@example.test|pass']);
            $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product), CheckoutPaymentIntents::manualQris());

            $this->assertTrue($order->expires_at->equalTo($startedAt->copy()->addMinute()));

            Carbon::setTestNow($startedAt->copy()->addMinutes(2));
            $this->artisan('orders:expire-pending')->assertSuccessful();

            $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
            $this->assertSame(0, $product->fresh()->reserved_stock);
            $this->assertNull($stockItem->fresh()->order_item_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_paid_after_expired_records_payment_without_mutating_stock(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 1), CheckoutPaymentIntents::manualQris(now()->subMinute()));
        app(OrderStateMachine::class)->expirePendingOrder($order);

        $latePaid = app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->first());
        $latePaidReplay = app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->first()->fresh());

        $this->assertTrue($latePaid->newlyApplied);
        $this->assertFalse($latePaidReplay->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $latePaid->orderStatus());
        $this->assertSame(PaymentStatus::Paid, $latePaid->paymentStatus());
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $order->payments()->first()->fresh()->status);
        $this->assertSame(1, Product::first()->stock);
        $this->assertSame(0, Product::first()->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_pending_refund_releases_reservation_and_credential_once(): void
    {
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'pending@example.test|pass']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product));
        $payment = $order->payments()->first();

        $refunded = app(OrderStateMachine::class)->applyPaymentRefunded($payment);
        $refundReplay = app(OrderStateMachine::class)->applyPaymentRefunded($payment->fresh());

        $this->assertTrue($refunded->newlyApplied);
        $this->assertFalse($refundReplay->newlyApplied);
        $this->assertSame(OrderStatus::Cancelled, $refunded->orderStatus());
        $this->assertSame(PaymentStatus::Refunded, $refunded->paymentStatus());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_processing_refund_releases_credential_without_releasing_sold_stock(): void
    {
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'processing@example.test|pass']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product));
        $payment = $order->payments()->first();
        app(OrderStateMachine::class)->markPaymentPaid($payment);

        app(OrderStateMachine::class)->markPaymentRefunded($payment->fresh());
        app(OrderStateMachine::class)->markPaymentRefunded($payment->fresh());

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    public function test_completed_refund_keeps_order_completed_and_releases_credential(): void
    {
        $this->pakasirConfigIsReady();
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'completed@example.test|pass']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->first();
        app(OrderStateMachine::class)->markPaymentPaid($payment);

        app(OrderStateMachine::class)->markPaymentRefunded($payment->fresh());
        app(OrderStateMachine::class)->markPaymentRefunded($payment->fresh());

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    public function test_checkout_rejects_inactive_soft_deleted_and_understocked_products(): void
    {
        $this->expectException(InvalidCheckout::class);
        app(OrderCheckoutService::class)->checkout($this->cartWithProduct(Product::factory()->create(['is_active' => false])));
    }

    public function test_checkout_rejects_soft_deleted_product(): void
    {
        $product = Product::factory()->create();
        $cart = $this->cartWithProduct($product);
        $product->delete();

        $this->expectException(InvalidCheckout::class);
        app(OrderCheckoutService::class)->checkout($cart);
    }

    public function test_checkout_rejects_understocked_product(): void
    {
        $this->expectException(InsufficientStock::class);
        app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 0));
    }

    public function test_reference_collision_retries(): void
    {
        Order::factory()->create(['invoice_number' => 'INV-COLLIDE']);
        $this->app->bind(InvoiceNumberGenerator::class, fn () => new class extends InvoiceNumberGenerator
        {
            public int $calls = 0;

            public function invoiceNumber(): string
            {
                return $this->calls++ === 0 ? 'INV-COLLIDE' : 'INV-OK';
            }

            public function merchantReference(): string
            {
                return 'PAY-OK-'.$this->calls;
            }
        });

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct());

        $this->assertSame('INV-OK', $order->invoice_number);
    }

    public function test_db_unique_movement_constraint_is_enforced(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct());
        $movement = InventoryMovement::first();

        $this->expectException(QueryException::class);
        InventoryMovement::create($movement->only(['product_id', 'order_id', 'type', 'quantity', 'notes']) + ['created_at' => now()]);
    }

    public function test_inventory_ledger_verifies_sale_and_release_proofs(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2));
        $items = $order->orderItems()->get();
        $movements = InventoryMovement::where('order_id', $order->id)->get();
        $ledger = app(InventoryLedger::class);

        $this->assertFalse($ledger->hasSaleProof($items, $movements));

        app(OrderStateMachine::class)->markPaymentPaid($order->payments()->first());

        $this->assertTrue($ledger->hasSaleProof($items, InventoryMovement::where('order_id', $order->id)->get()));

        $releaseOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinute()));
        app(OrderStateMachine::class)->expirePendingOrder($releaseOrder);

        $this->assertTrue($ledger->hasReleaseProof($releaseOrder->orderItems()->get(), InventoryMovement::where('order_id', $releaseOrder->id)->get()));
    }

    public function test_expiry_command_chunk_one_processes_all_due_orders(): void
    {
        config(['services.telegram.token' => null]);

        $orders = collect([
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2))),
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2))),
            app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2))),
        ]);

        $this->artisan('orders:expire-pending', ['--chunk' => 1])
            ->expectsOutputToContain('orders:expire-pending completed cleanly: processed=3 skipped=0 failed=0')
            ->assertSuccessful();

        $orders->each(fn (Order $order) => $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status));
        $this->assertSame(3, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    public function test_expiry_command_releases_credential_allocation(): void
    {
        config(['services.telegram.token' => null]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'expiry@example.test|pass']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product), CheckoutPaymentIntents::manualQris(now()->subMinutes(2)));

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payments()->firstOrFail()->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($stockItem->fresh()->order_item_id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
    }

    public function test_expiry_command_reports_skipped_orders(): void
    {
        config(['services.telegram.token' => null]);

        $skipped = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2)));
        $processed = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2)));
        $this->app->instance(OrderLifecycle::class, new class($skipped->id) extends OrderStateMachine
        {
            public function __construct(private readonly string $skippedOrderId)
            {
                parent::__construct(app(InventoryLedger::class));
            }

            public function applyPendingExpiry(Order $order): OrderLifecycleResult
            {
                if ($order->id === $this->skippedOrderId) {
                    return new OrderLifecycleResult($order->fresh(['payments']), $order->payments()->first(), false);
                }

                return parent::applyPendingExpiry($order);
            }
        });

        $this->artisan('orders:expire-pending', ['--chunk' => 1])
            ->expectsOutputToContain('orders:expire-pending completed with skips: processed=1 skipped=1 failed=0')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::PendingPayment, $skipped->fresh()->order_status);
        $this->assertSame(OrderStatus::Expired, $processed->fresh()->order_status);
    }

    public function test_expiry_command_reports_partial_failures_and_continues(): void
    {
        config(['services.telegram.token' => null]);

        $failed = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2)));
        $processed = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinutes(2)));
        $this->app->instance(OrderLifecycle::class, new class($failed->id) extends OrderStateMachine
        {
            public function __construct(private readonly string $failedOrderId)
            {
                parent::__construct(app(InventoryLedger::class));
            }

            public function applyPendingExpiry(Order $order): OrderLifecycleResult
            {
                if ($order->id === $this->failedOrderId) {
                    throw new InvalidOrderTransition('Injected order failure.');
                }

                return parent::applyPendingExpiry($order);
            }
        });

        $this->artisan('orders:expire-pending', ['--chunk' => 1])
            ->expectsOutputToContain('orders:expire-pending completed with partial failures: processed=1 skipped=0 failed=1')
            ->assertFailed();

        $this->assertSame(OrderStatus::PendingPayment, $failed->fresh()->order_status);
        $this->assertSame(OrderStatus::Expired, $processed->fresh()->order_status);
    }

    public function test_paid_and_release_still_work_after_product_soft_deleted_post_checkout(): void
    {
        $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 0]);
        $paidOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product));
        $product->delete();

        app(OrderStateMachine::class)->markPaymentPaid($paidOrder->payments()->first());

        $this->assertSame(OrderStatus::Processing, $paidOrder->fresh()->order_status);
        $this->assertSame(2, $product->fresh()->stock);

        $product2 = Product::factory()->create(['stock' => 3, 'reserved_stock' => 0]);
        $expiredOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct($product2), CheckoutPaymentIntents::manualQris(now()->subMinute()));
        $product2->delete();

        app(OrderStateMachine::class)->expirePendingOrder($expiredOrder);
        $this->assertSame(OrderStatus::Expired, $expiredOrder->fresh()->order_status);
        $this->assertSame(0, $product2->fresh()->reserved_stock);
    }

    public function test_sale_requires_matching_reservation_and_does_not_mutate_stock(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2));
        InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Reservation)->delete();
        $before = Product::first()->only(['stock', 'reserved_stock']);

        try {
            app(OrderStateMachine::class)->markPaymentPaid($order->payments()->first());
            $this->fail('Expected missing reservation proof to fail.');
        } catch (InvalidOrderTransition) {
            $after = Product::first()->only(['stock', 'reserved_stock']);
            $this->assertSame($before, $after);
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        }
    }

    public function test_release_requires_matching_reservation_and_does_not_mutate_stock(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), CheckoutPaymentIntents::manualQris(now()->subMinute()));
        InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Reservation)->delete();
        $before = Product::first()->only(['stock', 'reserved_stock']);

        try {
            app(OrderStateMachine::class)->expirePendingOrder($order);
            $this->fail('Expected missing reservation proof to fail.');
        } catch (InvalidOrderTransition) {
            $after = Product::first()->only(['stock', 'reserved_stock']);
            $this->assertSame($before, $after);
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        }
    }

    public function test_active_cart_for_reuses_existing_active_cart(): void
    {
        $customer = Customer::factory()->create();
        $service = app(CartService::class);

        $first = $service->activeCartFor($customer);
        $second = $service->activeCartFor($customer);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Cart::where('customer_id', $customer->id)->where('status', CartStatus::Active)->count());
    }

    private function cartWithProduct(?Product $product = null, int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create();
        $product ??= Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
