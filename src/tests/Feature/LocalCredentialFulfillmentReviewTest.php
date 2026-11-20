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
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramAdminPaidOrderNotifier;
use App\Services\Telegram\TelegramOutboundEffectService;
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

    public function test_owner_fulfills_numeric_stock_once_and_the_product_remains_purchasable(): void
    {
        [$order, , $principal, $product] = $this->purchase('api');
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $item = $order->orderItems()->sole();
        $secret = 'manual@example.com | fixture-password';
        $route = route('admin.manual-fulfillment-reviews.credentials', $review);
        $payload = ['items' => [$item->id => ['format' => 'legacy', 'credentials' => $secret]]];

        $outbound = app(TelegramOutboundEffectService::class);
        $oldReceipt = $outbound->recordReceipt($order->fresh());
        $this->assertNotNull($oldReceipt);
        $this->assertFalse($oldReceipt->outcome_metadata['contains_credentials']);
        $order->update(['telegram_receipt_sent_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post($route, $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->post($route, ['items' => [$item->id + 1 => $payload['items'][$item->id]]])->assertSessionHasErrors('credentials');
        $this->assertDatabaseCount('product_stock_items', 0);
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->post($route, $payload)->assertSessionHasNoErrors();
        $this->post($route, $payload)->assertSessionHasNoErrors();

        $stock = $item->stockItems()->sole();
        $this->assertTrue($stock->is_order_only);
        $this->assertSame($secret, $stock->secret);
        $this->assertStringNotContainsString('fixture-password', $stock->getRawOriginal('secret'));
        $this->assertSame(4, $product->fresh()->availableStock());
        $this->assertSame(4, Product::withAvailability()->findOrFail($product->id)->availableStock());
        $this->assertTrue(Product::availableForPurchase()->whereKey($product->id)->exists());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('fulfilled', $review->fresh()->state);
        $this->assertSame('ready', app(CustomerApiOrders::class)->delivery($principal, $order->id)['data']['state']);

        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        $this->assertTrue(TelegramOutboundEffect::query()
            ->where('source_id', $order->id)
            ->where('effect_group_key', 'like', TelegramOutboundEffectService::manualReceiptGroupKey($order->id).'%')->exists());
        $effect = $outbound->recordReceipt($order->fresh());
        $this->assertNotNull($effect);
        $this->assertNotSame($oldReceipt->id, $effect->id);
        $this->assertStringStartsWith(TelegramOutboundEffectService::manualReceiptGroupKey($order->id), $effect->effect_group_key);
        $this->assertTrue($effect->outcome_metadata['contains_credentials']);
        $this->assertSame((string) $order->customer->telegram_id, $effect->telegram_chat_id);
        $this->assertSame($effect->id, $outbound->recordReceipt($order->fresh())->id);

        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 123]]));
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($oldReceipt);
        Http::assertNothingSent();
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());
        Http::assertSentCount(1);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
    }

    public function test_admin_paid_notice_links_directly_to_the_action_for_the_invoice(): void
    {
        config(['app.url' => 'https://shop.example.com']);
        [$order] = $this->purchase('bot');
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 123]]));
        $notifier = app(TelegramAdminPaidOrderNotifier::class);

        $notifier->sendEffect($order, '999123', outcome: ['resolved' => false, 'lines' => [], 'action' => 'Periksa dashboard']);
        $notifier->sendEffect($order, '999123', reviewNotice: true);

        $path = route('admin.manual-fulfillment-reviews.show', $review, absolute: false);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('menunggu akun lokal', $request['text']);
            $this->assertSame([
                'text' => '🔐 Isi akun pesanan',
                'url' => 'https://shop.example.com'.$path.'#local-credentials',
                'style' => 'primary',
            ], $request['reply_markup']['inline_keyboard'][0][0]);
        }
        Http::assertSentCount(2);

        $review->update(['state' => ManualFulfillmentReview::STATE_FULFILLED]);
        $notifier->sendEffect($order, '999123', outcome: [
            'resolved' => false,
            'lines' => ['pengiriman belum terkonfirmasi; jangan kirim ulang'],
            'action' => 'Periksa Manual Delivery Review.',
        ]);
        $fallback = Http::recorded()->last()[0];
        $this->assertStringContainsString('jangan kirim ulang', $fallback['text']);
        $this->assertSame(route('admin.orders.show', $order), $fallback['reply_markup']['inline_keyboard'][0][0]['url']);
        $this->assertSame('🧾 Buka detail invoice', $fallback['reply_markup']['inline_keyboard'][0][0]['text']);
    }

    public function test_bot_order_only_needs_one_admin_notice_and_hides_the_unlimited_stock_number(): void
    {
        [$order, , , $product] = $this->purchase('bot');
        $product->forceFill(['stock' => 2147483647])->save();
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $paid = TelegramOutboundEffect::where('source_id', $order->id)
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole();
        $notice = TelegramOutboundEffect::where('source_id', $review->id)
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->sole();
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 123]]));
        $pipeline = app(AdminOrderNotificationDeliveryPipeline::class);
        $pipeline->deliver($notice);
        $pipeline->deliver($paid);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('2147483647', Http::recorded()->sole()[0]['text']);
        $this->assertStringContainsString('∞', Http::recorded()->sole()[0]['text']);
    }

    public function test_confirmed_manual_delivery_replaces_both_legacy_notices_once(): void
    {
        [$order] = $this->purchase('bot');
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $paid = TelegramOutboundEffect::where('source_id', $order->id)
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole();
        $legacy = TelegramOutboundEffect::where('source_id', $review->id)
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)->sole();
        $failCleanupOnce = true;
        Http::fake(function ($request) use (&$failCleanupOnce) {
            if ($failCleanupOnce && str_ends_with($request->url(), '/deleteMessage')) {
                $failCleanupOnce = false;

                return Http::response(['ok' => false], 503);
            }

            return Http::response(['ok' => true, 'result' => [
                'message_id' => str_contains((string) ($request['text'] ?? ''), 'Hasil order')
                    ? 103 : ((string) ($request['chat_id'] ?? '') === '999123' ? 101 : 201),
            ]]);
        });
        $pipeline = app(AdminOrderNotificationDeliveryPipeline::class);
        $pipeline->deliver($paid);
        $legacy->update(['state' => TelegramOutboundEffectState::Sent, 'telegram_message_id' => '102', 'sent_at' => now()]);

        $item = $order->orderItems()->sole();
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $review), [
                'items' => [$item->id => ['format' => 'legacy', 'credentials' => 'fixture@example.com | fixture-password']],
            ])->assertSessionHasNoErrors();
        $this->assertSame(0, $pipeline->recoverPaidOutcomeUpdates());

        $receipt = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        app(ReceiptDeliveryPipeline::class)->deliver($receipt);
        $result = TelegramOutboundEffect::where('supersedes_effect_id', $paid->id)->sole();
        $before = Http::recorded()->count();
        $pipeline->deliver($result);
        $pipeline->deliver($result->fresh());
        $this->assertSame(1, $pipeline->recoverLocalReviewCleanup(100));
        $this->assertSame(0, $pipeline->recoverLocalReviewCleanup(100));

        $requests = Http::recorded()->slice($before)->values();
        $this->assertCount(4, $requests);
        $this->assertStringContainsString('Hasil order', $requests[0][0]['text']);
        $deleted = $requests->slice(1)->map(function ($record) {
            $this->assertStringEndsWith('/deleteMessage', $record[0]->url());

            return (string) $record[0]['message_id'];
        })->all();
        $this->assertEqualsCanonicalizing(['101', '102'], array_values(array_unique($deleted)));
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
        $effect = $review->reason === ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING && $review->order->api_order_claim_id === null
            ? TelegramOutboundEffect::where('source_id', $review->order_id)->where('effect_type', TelegramOutboundEffectType::AdminOrderPaidNotification->value)->sole()
            : $notice;
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());
        $this->artisan('telegram:recover-outbound')->assertSuccessful();
        Http::assertSentCount(1);
        $text = (string) Http::recorded()->sole()[0]['text'];
        $this->assertStringContainsString($effect->id === $notice->id ? 'Review fulfillment' : 'Order perlu ditinjau', $text);
        $this->assertStringContainsString($review->order->invoice_number, $text);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
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
