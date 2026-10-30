<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\CustomerApi\CustomerApiOrders;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class LocalCredentialFulfillmentReviewTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.token' => 'fixture-token', 'services.telegram.admin_telegram_chat_id' => '999123']);
    }

    public static function channels(): array
    {
        return [['api', 'IDR'], ['bot', 'IDR'], ['api', 'USDT'], ['bot', 'USDT']];
    }

    #[DataProvider('channels')]
    public function test_paid_legacy_checkout_records_one_review_and_delivers_one_owner_notice_across_replays(string $channel, string $currency): void
    {
        [$order, $replay, $principal, $product] = $this->purchase($channel, currency: $currency);
        $this->assertSame($order->id, $replay()->id);
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());

        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame('local_credentials_missing', $review->reason);
        $this->assertSame('pending', $review->state);
        $this->assertSame($order->payments()->sole()->id, $review->payment_id);
        $this->assertSame($currency === 'IDR' ? 5000 : 2_000_000, $review->amount);
        $this->assertSame($currency, $review->currency);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame($currency === 'IDR' ? 15_000 : 20_000, app(StoreBalanceLedger::class)->snapshot($order->customer)->balance());
        $this->assertSame($currency === 'USDT' ? 3_000_000 : 5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($order->customer)->balanceMicro());

        if ($principal !== null) {
            $summary = app(CustomerApiOrders::class)->detail($principal, $order->id)['data'];
            $this->assertSame(['state' => 'review', 'ordered_units' => 1, 'allocated_units' => 0,
                'released_units' => 0, 'processing' => false, 'review_required' => true], $summary['fulfillment']);
            $delivery = app(CustomerApiOrders::class)->delivery($principal, $order->id)['data'];
            $this->assertSame('not_ready', $delivery['state']);
            $this->assertSame([], $delivery['units']);
        }

        $this->assertSame(1, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)->where('source_id', $review->id)->count());
        $this->assertOwnerNoticeOnce($review);
    }

    #[DataProvider('channels')]
    public function test_successful_credential_allocation_does_not_create_a_manual_review(string $channel, string $currency): void
    {
        [$order, $replay] = $this->purchase($channel, managed: true, currency: $currency);
        $replay();
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());
        $this->assertSame(1, ProductStockItem::whereIn('order_item_id', $order->orderItems()->select('id'))->count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
        $this->assertSame(0, TelegramOutboundEffect::where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->count());
    }

    public function test_fully_allocated_local_stock_order_appears_completed_in_order_history(): void
    {
        [$order] = $this->purchase('bot', managed: true);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, ProductStockItem::whereIn('order_item_id', $order->orderItems()->select('id'))->count());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $customer = $order->customer;
        $customer->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($customer, 'customer')->get(route('customer.orders.index'))
            ->assertOk()->assertSee($order->invoice_number)->assertSee('Selesai');
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->get(route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => 'completed']))
            ->assertOk()->assertSee($order->invoice_number)->assertSee('data-order-filter-groups="all completed"', false);
    }

    public function test_recovery_completes_old_fully_allocated_local_orders_but_not_missing_credentials(): void
    {
        [$completed] = $this->purchase('bot', managed: true);
        $completed->forceFill(['order_status' => OrderStatus::Processing])->save();
        $missing = Order::factory()->create(['order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);

        $this->artisan('orders:recover-local-completion')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $completed->fresh()->order_status);
        $this->assertSame(OrderStatus::Processing, $missing->fresh()->order_status);
        $this->artisan('orders:recover-local-completion')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $completed->fresh()->order_status);
    }

    public function test_recovery_does_not_complete_an_order_with_open_manual_review(): void
    {
        [$order] = $this->purchase('bot', managed: true);
        $order->forceFill(['order_status' => OrderStatus::Processing])->save();
        $payment = $order->payments()->sole();
        ManualFulfillmentReview::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        $this->artisan('orders:recover-local-completion')->assertSuccessful();
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    public function test_recovery_cursor_moves_past_ineligible_orders(): void
    {
        [$eligible] = $this->purchase('bot', managed: true);
        $eligible->forceFill(['order_status' => OrderStatus::Processing])->save();
        $blocked = Order::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000001',
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $product = Product::factory()->create();
        $item = OrderItem::factory()->for($blocked, 'order')->for($product, 'product')->create();
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $item->id, 'secret' => 'fixture']);

        $this->artisan('orders:recover-local-completion --limit=1')->assertSuccessful();
        $this->assertSame(OrderStatus::Processing, $eligible->fresh()->order_status);
        $this->artisan('orders:recover-local-completion --limit=1')->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $eligible->fresh()->order_status);
        $this->assertSame(OrderStatus::Processing, $blocked->fresh()->order_status);
    }

    public function test_late_legacy_payment_preserves_one_late_review_and_one_owner_notice(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);
        $carts = app(CartService::class);
        $cart = $carts->activeCartFor($customer);
        $carts->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->applyPendingCancellation($order);
        $payment = $order->payments()->sole();
        $lifecycle->applyPaymentPaid($payment);
        $lifecycle->applyPaymentPaid($payment->fresh());
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame(ManualFulfillmentReview::REASON_LATE_PAYMENT, $review->reason);
        $this->assertSame('pending', $review->state);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(1, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)->where('source_id', $review->id)->count());
        $this->assertOwnerNoticeOnce($review);
    }

    #[DataProvider('channels')]
    public function test_missing_owner_target_does_not_block_payment_and_recovery_materializes_the_notice(string $channel, string $currency): void
    {
        config(['services.telegram.admin_telegram_chat_id' => null]);
        [$order] = $this->purchase($channel, currency: $currency);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(0, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)->count());
        config(['services.telegram.admin_telegram_chat_id' => '999123']);
        $this->assertOwnerNoticeOnce($review);
    }

    private function assertOwnerNoticeOnce(ManualFulfillmentReview $review): void
    {
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $notice = TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)
            ->where('source_id', $review->id)->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->sole();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 123]]));
        $pipeline = app(AdminOrderNotificationDeliveryPipeline::class);
        $pipeline->deliver($notice);
        $pipeline->deliver($notice->fresh());
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Http::assertSentCount(1);
        $text = (string) Http::recorded()->sole()[0]['text'];
        $this->assertStringContainsString('Review fulfillment', $text);
        $this->assertStringContainsString('diperlukan', $text);
        $this->assertStringContainsString($review->order->invoice_number, $text);
        $this->assertSame(TelegramOutboundEffectState::Sent, $notice->fresh()->state);
        $this->assertSame(1, TelegramOutboundEffect::where('source_type', ManualFulfillmentReview::class)->where('source_id', $review->id)->count());
    }

    private function purchase(string $channel, bool $managed = false, string $currency = 'IDR'): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 765432109]);
        $product = Product::factory()->create(['price_amount' => 5000, 'usdt_price_amount' => 2_000_000, 'stock' => $managed ? 1 : 5, 'reserved_stock' => 0]);
        if ($managed) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'fixture-credential']);
        }
        app(StoreBalanceLedger::class)->creditTopUp($customer, 20_000, 'fixture-funding', 'fixture-funding');
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer, new VerifiedUsdtFunding(
            $customer->id, UsdtMicroAmount::fromUnits(5_000_000), 'fixture-usdt-funding', 'fixture-receipt', 'v1',
        ), 'fixture-usdt-funding');
        if ($channel === 'bot') {
            $carts = app(CartService::class);
            $cart = $carts->activeCartFor($customer);
            $carts->addItem($cart, $product, 1);
            $checkout = app(OrderCheckoutService::class);
            $replay = fn () => $checkout->checkout($cart,
                $currency === 'USDT' ? CheckoutPaymentIntents::usdtBalance() : CheckoutPaymentIntents::storeBalance(), 'bot-legacy-fixture');

            return [$replay(), $replay, null, $product];
        }

        $credentials = app(CustomerApiCredentials::class);
        $principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $http = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'product_id' => (string) $product->id, 'quantity' => 1, 'preference' => $currency === 'USDT' ? 'usd_only' : 'idr_only',
            'expected_totals' => $currency === 'USDT' ? ['USD' => '2'] : ['IDR' => '5000'],
        ], JSON_THROW_ON_ERROR));
        $http->headers->set('Idempotency-Key', 'legacy-fixture');
        $request = CustomerApiOrderRequest::fromRequest($http);
        $checkout = app(CustomerApiOrderCheckout::class);
        $replay = fn () => $checkout->place($principal, $request)->order;

        return [$replay(), $replay, $principal, $product];
    }
}
