<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\SalesProofPost;
use App\Models\StoreBalanceEntry;
use App\Models\Supplier;
use App\Models\SupplierAccountUnit;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Warranty\MissingFulfillmentRefundService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class BuyMailFulfillmentTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_only_verified_payment_acquires_and_delivers_order_owned_accounts_once(): void
    {
        [$supplier, $order] = $this->fixture(2);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            $reference = $request['reference'];

            return Http::response($this->delivery($reference, 2, 2), 201);
        });
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::assertNothingSent();
        app(OrderLifecycle::class)->applyManualCompletion($order);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::assertSentCount(1);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(90_400_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $items = ProductStockItem::where('order_item_id', $order->orderItems()->sole()->id)->get();
        $this->assertCount(2, $items);
        $this->assertSame('fixture1@gmail.com', $items[0]->credential_payload['email']);
        $this->assertStringNotContainsString('synthetic-password', DB::table('product_stock_items')->value('credential_payload'));
        $this->assertSame('PR1', $fulfillment->fresh()->purchases()->sole()->account_units[0]['id']);
        $units = SupplierAccountUnit::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $units);
        $this->assertSame(8000, $units[0]->retail_amount);
        $this->assertSame('PR1', $units[0]->external_id);
        $this->assertArrayNotHasKey('external_id', $units[0]->toArray());
        $this->assertSame(now()->addHours(2)->timestamp, $units[0]->refund_deadline->timestamp);
    }

    private function fixture(int $quantity): array
    {
        $supplier = Supplier::forceCreate(['name' => 'Private Gmail source', 'provider' => 'buymail', 'api_key' => 'synthetic-test-key', 'currency' => 'IDR', 'currency_confirmed' => true, 'exchange_rate_idr' => 1, 'is_active' => true]);
        Http::fake(fn () => Http::response([
            'id' => '123', 'currency' => 'IDR', 'balance' => 100000,
            'price_per_account' => 4800, 'stock_available' => 20, 'refunds_enabled' => true,
            'refund_window_minutes' => 120, 'limits' => ['max_quantity_per_order' => 50],
        ]));
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier));
        $sku = SupplierCatalogItem::where('supplier_id', $supplier->id)->sole();
        $product = Product::factory()->create(['stock' => 0, 'price_amount' => 8000]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 790001]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        Http::swap(new Factory);
        Http::preventStrayRequests();

        return [$supplier, $order];
    }

    public function test_partial_acquisition_settles_only_delivered_units_and_buys_only_the_remainder(): void
    {
        [$supplier, $order] = $this->fixture(3);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();
            $payload = $this->delivery($request['reference'], $request['quantity'], count($requests) === 1 ? 2 : 1);
            if (count($requests) > 1) {
                $payload['order']['id'] = 'ORD2';
                $payload['order']['accounts'][0]['id'] = 'PR3';
                $payload['order']['accounts'][0]['order_id'] = 'ORD2';
                $payload['order']['accounts'][0]['email'] = 'fixture3@gmail.com';
            }

            return Http::response($payload, 201);
        });
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(90_400_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(2, ProductStockItem::where('order_item_id', $order->orderItems()->sole()->id)->count());
        $delivery = app(CredentialDeliveryPresentation::class)->forCustomer($order->customer, $order->fresh());
        $this->assertCount(2, $delivery->groups()[0]->accounts());
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame([3, 1], array_column($requests, 'quantity'));
        $this->assertNotSame($requests[0]['reference'], $requests[1]['reference']);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(85_600_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(3, ProductStockItem::count());
    }

    public function test_balance_sync_during_a_purchase_cannot_apply_the_same_supplier_debit_twice(): void
    {
        [$supplier, $order] = $this->fixture(2);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $syncApplied = null;
        Http::fake(function ($request) use ($supplier, &$syncApplied) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => '123', 'currency' => 'IDR', 'balance' => 90400,
                    'price_per_account' => 4800, 'stock_available' => 18, 'refunds_enabled' => true,
                    'refund_window_minutes' => 120, 'limits' => ['max_quantity_per_order' => 50],
                ]);
            }
            $syncApplied = app(SupplierCatalogSync::class)->sync($supplier->fresh());

            return Http::response($this->delivery($request['reference'], 2, 2), 201);
        });
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertFalse($syncApplied);
        $this->assertSame(90_400_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    public function test_a_proven_missing_unit_refunds_only_its_retail_price_and_preserves_delivered_accounts(): void
    {
        [$supplier, $order] = $this->fixture(3);
        $customer = $order->customer;
        $payment = $order->payments()->sole();
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 24000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 24000, 'order', $order->id, 'debit');
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $payment->refresh()->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            $posts++;

            return $posts === 1 ? Http::response($this->delivery($request['reference'], 3, 2), 201)
                : Http::response(['error' => ['code' => 'out_of_stock']], 409);
        });
        $fulfillment = app(SupplierFulfillmentService::class);
        $fulfillment->process($f->id);
        $fulfillment->process($f->id);
        $this->assertSame('review', $f->fresh()->state);
        $refunds = app(MissingFulfillmentRefundService::class);
        $refunds->request($customer, $order->orderItems()->sole()->id);
        $refunds->request($customer, $order->orderItems()->sole()->id);
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(1, OrderRefund::count());
        $this->assertSame(8000, OrderRefund::sole()->amount);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(2, ProductStockItem::whereNull('retired_at')->count());
        $this->assertSame(90_400_000_000, $supplier->fresh()->balance_micros);
        Http::assertSentCount(2);
    }

    public function test_missing_external_refund_requires_confirmed_transfer_before_order_completion(): void
    {
        [, $order] = $this->fixture(3);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            $posts++;

            return $posts === 1 ? Http::response($this->delivery($request['reference'], 3, 2), 201)
                : Http::response(['error' => ['code' => 'out_of_stock']], 409);
        });
        $fulfillment = app(SupplierFulfillmentService::class);
        $fulfillment->process($f->id);
        $fulfillment->process($f->id);
        $refund = app(MissingFulfillmentRefundService::class)->request($order->customer, $order->orderItems()->sole()->id);
        $this->assertSame('requested', $refund->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $settlements = app(WarrantySettlementService::class);
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'uncertain');
        $this->assertSame('uncertain', $refund->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'success');
        $settlements->confirmExternalRefund($owner, $refund->id, 8000, 'IDR', 'synthetic-remainder-transfer', 'success');
        $this->assertSame('confirmed', $refund->fresh()->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(0, StoreBalanceEntry::count());
        $this->assertSame(2, ProductStockItem::whereNull('retired_at')->count());
        $this->assertSame(1, $f->fresh()->refunded_quantity);
    }

    public function test_when_no_account_was_acquired_the_full_missing_refund_does_not_publish_a_successful_sale(): void
    {
        [, $order] = $this->fixture(1);
        $customer = $order->customer;
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 8000, StoreBalanceEntryKind::TopUpCredit, 'test', 'funding', 'funding');
        $ledger->debit($customer, 8000, 'order', $order->id, 'debit');
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $payment = $order->payments()->sole();
        $payment->forceFill(['provider' => 'store_balance', 'method' => 'STORE_BALANCE'])->save();
        Http::fake(fn () => Http::response(['error' => ['code' => 'out_of_stock']], 409));
        app(SupplierFulfillmentService::class)->process(SupplierFulfillment::where('order_id', $order->id)->sole()->id);
        app(MissingFulfillmentRefundService::class)->request($customer, $order->orderItems()->sole()->id);
        $this->assertSame(8000, $ledger->snapshot($customer)->balance());
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(0, SalesProofPost::count());
    }

    public function test_daily_limit_pauses_new_buyers_until_supplier_midnight_even_if_profile_still_lists_stock(): void
    {
        [$supplier, $order] = $this->fixture(1);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn () => Http::response(['error' => ['code' => 'daily_limit_reached']], 409));
        app(SupplierFulfillmentService::class)->process(SupplierFulfillment::where('order_id', $order->id)->sole()->id);
        $this->assertSame(0, $order->orderItems()->sole()->product->fresh()->availableStock());
        $this->assertSame(now('Asia/Jakarta')->addDay()->startOfDay()->timestamp, $supplier->fresh()->purchase_paused_until->timestamp);
    }

    public function test_paid_purchase_is_processed_by_the_real_dedicated_supplier_queue_worker(): void
    {
        [, $order] = $this->fixture(1);
        $manager = new QueueManager($this->app);
        $manager->addConnector('database', fn () => new DatabaseConnector($this->app['db']));
        config(['queue.default' => 'database', 'queue.connections.supplier.driver' => 'database',
            'queue.connections.supplier.connection' => config('database.default'),
            'queue.connections.database.connection' => config('database.default')]);
        Queue::swap($manager);
        Http::fake(fn ($request) => Http::response($this->delivery($request['reference'], 1, 1), 201));
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->assertGreaterThan(0, Queue::connection('supplier')->size('suppliers'));
        $this->artisan('queue:work', ['connection' => 'supplier', '--queue' => 'suppliers', '--once' => true, '--tries' => 1, '--timeout' => 120])->assertSuccessful();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(0, Queue::connection('supplier')->size('suppliers'));
        Http::assertSentCount(1);
        $this->assertSame(180, config('queue.connections.supplier.retry_after'));
    }

    public function test_a_paid_order_flag_without_a_verified_payment_record_cannot_spend_supplier_money(): void
    {
        [$supplier, $order] = $this->fixture(1);
        $order->update(['payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Processing]);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $f->update(['state' => 'pending', 'next_attempt_at' => now()]);
        Http::fake(fn ($request) => Http::response($this->delivery($request['reference'], 1, 1), 201));
        app(SupplierFulfillmentService::class)->process($f->id);
        Http::assertNothingSent();
        $this->assertSame(100_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, ProductStockItem::count());
    }

    private function delivery(string $reference, int $requested, int $delivered): array
    {
        return ['order' => [
            'id' => 'ORD1', 'reference' => $reference, 'status' => $requested === $delivered ? 'completed' : 'partial',
            'quantity_requested' => $requested, 'quantity_delivered' => $delivered, 'unit_price' => 4800,
            'amount_charged' => $delivered * 4800, 'currency' => 'IDR',
            'accounts' => array_map(fn ($i) => [
                'id' => 'PR'.$i, 'order_id' => 'ORD1', 'email' => 'fixture'.$i.'@gmail.com',
                'password' => 'synthetic-password-'.$i, 'price' => 4800, 'status' => 'active',
                'refund_deadline' => now()->addHours(2)->utc()->toIso8601String(),
            ], range(1, $delivered)),
        ]];
    }
}
