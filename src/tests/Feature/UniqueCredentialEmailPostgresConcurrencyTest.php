<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UniqueCredentialEmailPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Unique credential email checkout');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Http::preventStrayRequests();
    }

    public static function remainingEmails(): array
    {
        return ['only duplicate remains' => [false], 'distinct email remains' => [true]];
    }

    #[DataProvider('remainingEmails')]
    public function test_simultaneous_orders_for_one_customer_never_receive_the_same_email(bool $distinct): void
    {
        $customer = Customer::factory()->create();
        app(StoreBalanceLedger::class)->creditTopUp($customer, 50_000, 'unique-race-funding', 'unique-race-funding');
        $product = Product::factory()->create([
            'unique_credential_email' => true, 'price_amount' => 5000,
            'stock' => $distinct ? 3 : 2, 'reserved_stock' => 0,
        ]);
        $duplicates = [
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'race@example.test|first-password']),
            ProductStockItem::create(['product_id' => $product->id, 'secret' => ' RACE@example.test |changed-password']),
        ];
        $eligible = $distinct
            ? ProductStockItem::create(['product_id' => $product->id, 'secret' => 'distinct@example.test|third-password'])
            : null;
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->checkout($carts[$index]->id), 'unique-email-race');

        $this->assertWorkersSucceeded($results);
        $orderIds = array_column($results, 'value');
        $this->assertCount(2, array_unique($orderIds));
        $this->assertSame(2, Order::whereIn('id', $orderIds)->where('payment_status', PaymentStatus::Paid)->count());
        $this->assertSame($distinct ? 2 : 1, Order::whereIn('id', $orderIds)->where('order_status', OrderStatus::Completed)->count());
        $this->assertSame($distinct ? 0 : 1, Order::whereIn('id', $orderIds)->where('order_status', OrderStatus::Processing)->count());
        $this->assertSame(40_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, ProductStockItem::whereIn('id', array_map(fn (ProductStockItem $item): int => $item->id, $duplicates))->whereNotNull('order_item_id')->count());
        $this->assertSame($distinct ? 2 : 1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        if ($eligible !== null) {
            $this->assertNotNull($eligible->fresh()->order_item_id);
            $this->assertDatabaseCount('manual_fulfillment_reviews', 0);
        } else {
            $review = ManualFulfillmentReview::sole();
            $this->assertSame('unique_email_unavailable', $review->reason);
            $this->assertSame('pending', $review->state);
            $this->assertSame(OrderStatus::Processing, $review->order->order_status);
            $item = $review->order->orderItems()->sole();
            $this->assertSame(1, $item->localQuantity());
            $this->assertSame(1, $item->deferred_local_quantity);
            $this->assertSame(0, $item->localStockQuantity());
        }
        foreach ($carts as $index => $cart) {
            $this->assertSame($orderIds[$index], $this->checkout($cart->id));
        }
        $this->assertSame(2, Order::count());
        $this->assertSame(40_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        Http::assertNothingSent();
    }

    public function test_simultaneous_unpaid_orders_cannot_reserve_duplicate_emails_before_either_payment_arrives(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'unique_credential_email' => true, 'price_amount' => 5000, 'stock' => 2, 'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'reserved@example.test|first-password']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => ' RESERVED@example.test |changed-password']);
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($carts): string {
            return (string) app(OrderCheckoutService::class)->checkout(
                Cart::findOrFail($carts[$index]->id), CheckoutPaymentIntents::manualQris(), 'pending-unique-'.$index,
            )->id;
        }, 'pending-unique-email-race');

        $this->assertWorkersSucceeded($results);
        $orderIds = array_column($results, 'value');
        $this->assertCount(2, array_unique($orderIds));
        $orders = Order::whereIn('id', $orderIds)->get();
        $this->assertCount(2, $orders);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        $deferred = [];
        foreach ($orders as $order) {
            $this->assertSame(PaymentStatus::Pending, $order->payment_status);
            $this->assertSame(OrderStatus::PendingPayment, $order->order_status);
            $deferred[] = $order->orderItems()->sole()->deferred_local_quantity;
            app(OrderLifecycle::class)->applyManualCompletion($order);
        }
        sort($deferred);
        $this->assertSame([0, 1], $deferred);
        $this->assertSame(1, Order::whereIn('id', $orderIds)->where('order_status', OrderStatus::Completed)->count());
        $this->assertSame(1, Order::whereIn('id', $orderIds)->where('order_status', OrderStatus::Processing)->count());
        $review = ManualFulfillmentReview::sole();
        $this->assertSame('unique_email_unavailable', $review->reason);
        $this->assertSame('pending', $review->state);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count());
        Http::assertNothingSent();
    }

    public function test_simultaneous_manual_submissions_for_one_review_create_one_account_and_one_receipt(): void
    {
        [$customer, $product, $reviews] = $this->deferredReviews(1);
        $review = $reviews[0];
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $item = $review->order->orderItems()->sole();

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->submitCredentials(
            $review->id,
            $owner->id,
            $index === 0 ? 'manual@example.test|first-password' : ' MANUAL@example.test |second-password',
        ), 'unique-email-manual-replay');

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(fn (array $result): array => json_decode($result['value'], true, flags: JSON_THROW_ON_ERROR), $results);
        $this->assertSame(['fulfilled', 'fulfilled'], array_column($outcomes, 'state'));
        $this->assertSame(1, array_sum(array_column($outcomes, 'receipts')));
        $this->assertSame('fulfilled', $review->fresh()->state);
        $this->assertSame($owner->id, $review->fresh()->actor_user_id);
        $this->assertNotNull($review->fresh()->resolved_at);
        $this->assertSame(OrderStatus::Completed, $review->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $review->order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $review->order->payments()->sole()->status);
        $account = ProductStockItem::where('order_item_id', $item->id)->sole();
        $this->assertContains($account->secret, ['manual@example.test|first-password', 'MANUAL@example.test |second-password']);
        $this->assertNull($account->supplier_purchase_id);
        $this->assertSame(1, AuditLog::where('subject_type', ManualFulfillmentReview::class)
            ->where('subject_id', (string) $review->id)
            ->where('action', 'manual_fulfillment_review.local_credentials_provided')->count());
        $this->assertSame(1, ManualFulfillmentReview::count());
        $this->assertSame(3, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(40_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        Http::assertNothingSent();
    }

    public function test_simultaneous_manual_reviews_for_one_buyer_reject_the_second_normalized_email(): void
    {
        [$customer, $product, $reviews] = $this->deferredReviews(2);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $itemIds = array_map(fn (ManualFulfillmentReview $review): int => $review->order->orderItems()->sole()->id, $reviews);

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->submitCredentials(
            $reviews[$index]->id,
            $owner->id,
            $index === 0 ? 'shared@example.test|first-password' : ' SHARED@example.test |second-password',
        ), 'unique-email-manual-orders');

        $this->assertWorkersSucceeded($results);
        $outcomes = array_map(fn (array $result): array => json_decode($result['value'], true, flags: JSON_THROW_ON_ERROR), $results);
        $states = array_column($outcomes, 'state');
        sort($states);
        $this->assertSame(['fulfilled', 'rejected'], $states);
        $this->assertSame(1, array_sum(array_column($outcomes, 'receipts')));
        $this->assertSame(1, ManualFulfillmentReview::where('state', 'fulfilled')->count());
        $this->assertSame(1, ManualFulfillmentReview::where('state', 'pending')->count());
        $this->assertSame(1, ProductStockItem::whereIn('order_item_id', $itemIds)->count());
        foreach ($reviews as $index => $review) {
            $order = $review->order->fresh();
            $accepted = $outcomes[$index]['state'] === 'fulfilled';
            $this->assertSame($accepted ? 'fulfilled' : 'pending', $review->fresh()->state);
            $this->assertSame($accepted ? OrderStatus::Completed : OrderStatus::Processing, $order->order_status);
            $this->assertSame(PaymentStatus::Paid, $order->payment_status);
            $this->assertSame(PaymentStatus::Paid, $order->payments()->sole()->status);
            $this->assertSame(5000, $review->amount);
            $this->assertSame($accepted ? 1 : 0, ProductStockItem::where('order_item_id', $itemIds[$index])->count());
        }
        $this->assertSame(4, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(2, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        Http::assertNothingSent();
    }

    public function test_manual_submission_racing_checkout_cannot_allocate_the_same_normalized_email(): void
    {
        [$customer, $product, $reviews] = $this->deferredReviews(1);
        $review = $reviews[0];
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $stock = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'contested@example.test|stock-password']);
        $product->increment('stock');
        $cart = $this->cart($customer, $product);

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $index === 0
            ? $this->submitCredentials($review->id, $owner->id, ' CONTESTED@example.test |manual-password')
            : $this->checkout($cart->id), 'unique-email-manual-checkout');

        $this->assertWorkersSucceeded($results);
        $manual = json_decode($results[0]['value'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains($manual['state'], ['fulfilled', 'rejected']);
        $manualWon = $manual['state'] === 'fulfilled';
        $checkout = Order::findOrFail($results[1]['value']);
        $manualItem = $review->order->orderItems()->sole();
        $checkoutItem = $checkout->orderItems()->sole();
        $this->assertSame($manualWon ? 'fulfilled' : 'pending', $review->fresh()->state);
        $this->assertSame($manualWon ? OrderStatus::Completed : OrderStatus::Processing, $review->order->fresh()->order_status);
        $this->assertSame($manualWon ? OrderStatus::Processing : OrderStatus::Completed, $checkout->order_status);
        $this->assertSame($manualWon ? 1 : 0, $manual['receipts']);
        $this->assertSame(PaymentStatus::Paid, $checkout->payment_status);
        $this->assertSame(PaymentStatus::Paid, $checkout->payments()->sole()->status);
        $this->assertSame($manualWon ? 1 : 0, $checkoutItem->deferred_local_quantity);
        $this->assertSame(1, ProductStockItem::whereIn('order_item_id', [$manualItem->id, $checkoutItem->id])->count());
        $this->assertSame($manualWon ? null : $checkoutItem->id, $stock->fresh()->order_item_id);
        $this->assertSame($manualWon ? 1 : 0, ProductStockItem::where('order_item_id', $manualItem->id)->count());
        $this->assertSame(1, ManualFulfillmentReview::where('state', 'pending')->count());
        if ($manualWon) {
            $checkoutReview = ManualFulfillmentReview::where('order_id', $checkout->id)->sole();
            $this->assertSame('unique_email_unavailable', $checkoutReview->reason);
            $this->assertSame(5000, $checkoutReview->amount);
        } else {
            $this->assertSame(0, ManualFulfillmentReview::where('order_id', $checkout->id)->count());
        }
        $this->assertSame($manualWon ? 2 : 1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame($checkout->id, $this->checkout($cart->id));
        $this->assertSame(3, Order::count());
        $this->assertSame(35_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        Http::assertNothingSent();
    }

    public function test_cancellation_racing_checkout_releases_the_original_reservation_without_reusing_a_live_email(): void
    {
        $customer = Customer::factory()->create();
        app(StoreBalanceLedger::class)->creditTopUp($customer, 50_000, 'release-race-funding', 'release-race-funding');
        $product = Product::factory()->create([
            'unique_credential_email' => true, 'price_amount' => 5000, 'stock' => 2, 'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'release@example.test|first-password']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => ' RELEASE@example.test |second-password']);
        $pending = app(OrderCheckoutService::class)->checkout(
            $this->cart($customer, $product), CheckoutPaymentIntents::manualQris(), 'release-race-pending',
        );
        $pendingItem = $pending->orderItems()->sole();
        $this->assertSame(1, ProductStockItem::where('order_item_id', $pendingItem->id)->count());
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $cart = $this->cart($customer, $product);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($pending, $cart): string {
            if ($index === 0) {
                return (string) app(OrderLifecycle::class)->applyPendingCancellation(Order::findOrFail($pending->id))->order->id;
            }

            return $this->checkout($cart->id);
        }, 'unique-email-cancel-checkout');

        $this->assertWorkersSucceeded($results);
        $this->assertSame((string) $pending->id, $results[0]['value']);
        $checkout = Order::findOrFail($results[1]['value']);
        $item = $checkout->orderItems()->sole();
        $this->assertContains($checkout->order_status, [OrderStatus::Completed, OrderStatus::Processing]);
        $completed = $checkout->order_status === OrderStatus::Completed;
        $this->assertSame(OrderStatus::Cancelled, $pending->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $pending->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Failed, $pending->payments()->sole()->status);
        $this->assertSame(PaymentStatus::Paid, $checkout->payment_status);
        $this->assertSame(PaymentStatus::Paid, $checkout->payments()->sole()->status);
        $this->assertSame(0, ProductStockItem::where('order_item_id', $pendingItem->id)->count());
        $this->assertSame($completed ? 1 : 0, ProductStockItem::where('order_item_id', $item->id)->count());
        $this->assertSame($completed ? 0 : 1, $item->deferred_local_quantity);
        $this->assertSame($completed ? 0 : 1, ManualFulfillmentReview::count());
        if (! $completed) {
            $review = ManualFulfillmentReview::sole();
            $this->assertSame($checkout->id, $review->order_id);
            $this->assertSame('pending', $review->state);
            $this->assertSame('unique_email_unavailable', $review->reason);
            $this->assertSame(5000, $review->amount);
        }
        $this->assertSame($completed ? 1 : 2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(45_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        app(OrderLifecycle::class)->applyPendingCancellation($pending->fresh());
        $this->assertSame($checkout->id, $this->checkout($cart->id));
        $this->assertSame(2, Order::count());
        $this->assertSame($completed ? 1 : 2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(45_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        Http::assertNothingSent();
    }

    /** @return array{Customer, Product, list<ManualFulfillmentReview>} */
    private function deferredReviews(int $count): array
    {
        $customer = Customer::factory()->create();
        app(StoreBalanceLedger::class)->creditTopUp($customer, 50_000, 'manual-race-funding', 'manual-race-funding');
        $product = Product::factory()->create([
            'unique_credential_email' => true, 'price_amount' => 5000, 'stock' => $count + 1, 'reserved_stock' => 0,
        ]);
        for ($index = 0; $index <= $count; $index++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'history@example.test|password-'.$index]);
        }
        $this->checkout($this->cart($customer, $product)->id);
        $reviews = [];
        for ($index = 0; $index < $count; $index++) {
            $order = Order::findOrFail($this->checkout($this->cart($customer, $product)->id));
            $this->assertSame(OrderStatus::Processing, $order->order_status);
            $this->assertSame(PaymentStatus::Paid, $order->payment_status);
            $this->assertSame(1, $order->orderItems()->sole()->deferred_local_quantity);
            $reviews[] = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        }

        return [$customer, $product, $reviews];
    }

    private function submitCredentials(int $reviewId, int $ownerId, string $credential): string
    {
        Queue::fake();
        $review = ManualFulfillmentReview::findOrFail($reviewId);
        $item = $review->order->orderItems()->sole();
        try {
            $resolved = app(ManualFulfillmentResolutionService::class)->provideLocalCredentials(
                $review, User::findOrFail($ownerId), [$item->id => [$credential]], 'Resolve the missing local account.',
            );
            $state = $resolved->state;
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credentials', $exception->errors());
            $state = 'rejected';
        }

        return json_encode([
            'state' => $state,
            'receipts' => Queue::pushed(SendTelegramReceipt::class)->count(),
        ], JSON_THROW_ON_ERROR);
    }

    private function cart(Customer $customer, Product $product): Cart
    {
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        return $cart;
    }

    private function checkout(string $cartId): string
    {
        $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cartId), CheckoutPaymentIntents::storeBalance(), 'unique-email-'.$cartId);

        return (string) $order->id;
    }
}
