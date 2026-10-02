<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierFulfillment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class SupplierIndependentRegressionTest extends NonTransactionalDatabaseTestCase
{
    public static function untrustedCompletePayloads(): array
    {
        return [
            'wrong SKU' => [['service_id' => 'not-the-ordered-sku']],
            'wrong original reference' => [['external_order_id' => 'not-the-original-reference']],
            'missing exact order identity' => [['order_id' => null]],
            'failed outcome with full payload' => [['status' => 'failed']],
        ];
    }

    #[DataProvider('untrustedCompletePayloads')]
    public function test_untrusted_purchase_evidence_never_authorizes_customer_delivery(array $overrides): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => Http::response(array_replace([
            'status' => 'delivered', 'order_id' => 'original-fixture-order',
            'service_id' => 'review-sku', 'quantity' => 1, 'total_cost' => '1',
            'products' => ['untrusted-customer-key'],
        ], $overrides), 201));

        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        Http::assertSentCount(1);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertSame(0, ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->count());
        $this->assertContains($fulfillment->fresh()->state, ['review', 'awaiting_delivery']);
        $this->assertSame(['untrusted-customer-key'], SupplierPurchase::sole()->delivery_payload);
        $this->assertNull(SupplierPurchase::sole()->supplier_order_id);
        $this->assertNull(SupplierPurchase::sole()->committed_at);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
    }

    public function test_duplicate_delivered_unit_cannot_satisfy_two_customer_units(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fake(fn () => Http::response([
            'status' => 'delivered', 'order_id' => 'duplicate-unit-order',
            'service_id' => 'review-sku', 'quantity' => 2, 'total_cost' => '2',
            'products' => ['same-single-use-key', 'same-single-use-key'],
        ], 201));

        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        Http::assertSentCount(1);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertSame(0, ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->count());
    }

    public function test_unknown_original_order_identity_blocks_refund_even_with_complete_credentials(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => Http::response([
            'status' => 'delivered', 'service_id' => 'review-sku',
            'quantity' => 1, 'total_cost' => '1', 'products' => ['unknown-original-key'],
        ], 201));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        $this->expectException(InvalidOrderTransition::class);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
    }

    public function test_partial_recovery_preserves_previously_acquired_complete_units(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fakeSequence()
            ->push(['status' => 'delivered', 'order_id' => 'recovery-order', 'quantity' => 2, 'products' => ['original-unit-A', 'original-unit-B']], 201)
            ->push(['status' => 'delivered', 'order_id' => 'recovery-order', 'quantity' => 2, 'total_cost' => '2', 'products' => ['original-unit-A']]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame(['original-unit-A', 'original-unit-B'], SupplierPurchase::sole()->delivery_payload);
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'recovery-order');
        $this->assertSame(['original-unit-A', 'original-unit-B'], SupplierPurchase::sole()->delivery_payload);
    }

    public function test_manual_replacement_settles_already_proven_original_debit(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'order_id' => 'partial-order', 'quantity' => 2, 'total_cost' => '2', 'products' => ['partial-original-key']], 201));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertNotNull(SupplierPurchase::sole()->committed_at);
        app(SupplierFulfillmentService::class)->provideManualCredentials($fulfillment->id, ['replacement-A', 'replacement-B']);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('settled', SupplierPurchase::sole()->state);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
    }

    public function test_locally_blocked_replay_cannot_prove_original_dispatched_purchase_rejected(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('Disposable timeout after possible debit'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();
        $purchase->update(['state' => 'sending', 'lease_expires_at' => now()->subSecond(), 'dispatched_at' => now()->subMinute(), 'replay_count' => 0]);
        $fulfillment->update(['state' => 'purchasing', 'next_attempt_at' => now()->subSecond()]);
        $before = Supplier::findOrFail($purchase->supplier_id)->reserved_balance_micros;
        RateLimiter::hit('supplier:budget:aivault', 60);
        for ($i = 1; $i < 60; $i++) {
            RateLimiter::hit('supplier:budget:aivault', 60);
        }
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertNotSame('rejected', $purchase->fresh()->state);
        $this->assertSame($before, Supplier::findOrFail($purchase->supplier_id)->reserved_balance_micros);
        $this->expectException(InvalidOrderTransition::class);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
    }

    public function test_admin_stock_modal_preserves_numeric_local_stock_despite_supplier_delivery_history(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => Http::response(['status' => 'delivered', 'order_id' => 'history-order', 'quantity' => 1, 'total_cost' => '1', 'products' => ['history-key']], 201));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $product = $order->orderItems()->sole()->product;
        ProductSupplierSource::where('product_id', $product->id)->update(['is_active' => false]);
        $product->forceFill(['stock' => 2, 'reserved_stock' => 0, 'supplier_available_stock' => 0])->save();
        $this->assertSame(2, $product->fresh()->localAvailableStock());
        $modal = app(AdminDashboardProductsData::class)->stockManagementFor($product->fresh());
        $this->assertSame(2, $modal['availableStock']);
    }

    public static function unconfirmedOperatorSessions(): array
    {
        return ['absent' => [[]], 'expired' => [['auth.password_confirmed_at' => time() - 901]]];
    }

    #[DataProvider('unconfirmedOperatorSessions')]
    public function test_alternate_review_retry_requires_recent_password_confirmation(array $session): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        $sku = SupplierCatalogItem::findOrFail($fulfillment->reserved_catalog_item_id);
        $sku->update(['orderable' => false]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $sku->update(['orderable' => true]);
        $owner = User::factory()->create(['role' => 'owner']);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        // Settlement queued its wakeup before this HTTP action. Observe only
        // jobs produced by the attempted retry, not fixture setup.
        Queue::fake();
        $this->actingAs($owner)->withSession($session)
            ->post(route('admin.manual-fulfillment-reviews.resolve', $review), ['action' => 'fulfill', 'reason' => 'Disposable operator retry'])
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('review', $fulfillment->fresh()->state);
        Queue::assertNotPushed(ProcessSupplierFulfillment::class);

        // Fetch/AJAX callers keep the machine-readable confirmation contract.
        $this->actingAs($owner)->withSession($session)
            ->postJson(route('admin.manual-fulfillment-reviews.resolve', $review), ['action' => 'fulfill', 'reason' => 'Disposable operator retry'])
            ->assertStatus(428);
        $this->assertSame('review', $fulfillment->fresh()->state);
        Queue::assertNotPushed(ProcessSupplierFulfillment::class);
    }

    public function test_untrusted_delivery_cannot_be_laundered_by_metadata_only_recovery(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fakeSequence()
            ->push(['status' => 'failed', 'order_id' => 'untrusted-order', 'quantity' => 1, 'total_cost' => '1', 'products' => ['untrusted-key']], 201)
            ->push(['status' => 'delivered', 'order_id' => 'untrusted-order', 'quantity' => 1, 'total_cost' => '1']);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertNull(SupplierPurchase::sole()->committed_at);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'untrusted-order');
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(['untrusted-key'], SupplierPurchase::sole()->delivery_payload);
        $this->assertSame(0, ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->count());
    }

    public function test_conflicting_recovery_does_not_union_or_discard_acquired_evidence(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fakeSequence()
            ->push(['status' => 'delivered', 'order_id' => 'conflict-order', 'quantity' => 2, 'products' => ['original-A', 'original-B']], 201)
            ->push(['status' => 'delivered', 'order_id' => 'conflict-order', 'quantity' => 2, 'total_cost' => '2', 'products' => ['unrelated-C']]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'conflict-order');
        $this->assertSame(['original-A', 'original-B'], SupplierPurchase::sole()->delivery_payload);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, Supplier::sole()->reserved_balance_micros);
        $this->assertNotNull(SupplierPurchase::sole()->committed_at);
        $this->assertSame(0, ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->count());
    }

    public function test_generic_history_preload_cannot_reclassify_numeric_local_stock(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => Http::response(['status' => 'delivered', 'order_id' => 'preload-order', 'quantity' => 1, 'total_cost' => '1', 'products' => ['history-key']], 201));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $product = $order->orderItems()->sole()->product;
        $product->forceFill(['stock' => 2, 'reserved_stock' => 0])->save();
        $preloaded = Product::query()->withExists('stockItems')->findOrFail($product->id);
        $this->assertSame(2, $preloaded->localAvailableStock());
        $this->assertSame(2, Product::query()->withAvailability()->findOrFail($product->id)->localAvailableStock());
    }

    public function test_fresh_confirmation_allows_original_source_retry_without_buying_in_request(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        $sku = SupplierCatalogItem::findOrFail($fulfillment->reserved_catalog_item_id);
        $sku->update(['orderable' => false]);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $sku->update(['orderable' => true]);
        $owner = User::factory()->create(['role' => 'owner']);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        Queue::fake();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.resolve', $review), ['action' => 'fulfill', 'reason' => 'Confirmed original source retry'])
            ->assertRedirect();
        $this->assertSame('pending', $fulfillment->fresh()->state);
        Queue::assertPushed(ProcessSupplierFulfillment::class);
        Http::assertNothingSent();
    }

    public function test_untrusted_order_identity_does_not_pin_recovery_to_the_wrong_acquisition(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fakeSequence()
            ->push(['status' => 'delivered', 'order_id' => 'unrelated-order', 'service_id' => 'wrong-sku', 'quantity' => 1, 'total_cost' => '1', 'products' => ['unrelated-key']], 201)
            ->push(['status' => 'pending', 'order_id' => 'verified-original', 'service_id' => 'review-sku', 'quantity' => 1, 'total_cost' => '1']);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertNull(SupplierPurchase::sole()->supplier_order_id);
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'verified-original');
        $this->assertSame('verified-original', SupplierPurchase::sole()->supplier_order_id);
        $this->assertNotNull(SupplierPurchase::sole()->committed_at);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->count());
        app(SupplierFulfillmentService::class)->provideManualCredentials($fulfillment->id, ['replacement-key']);
        $this->assertSame('settled', SupplierPurchase::sole()->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('replacement-key', ProductStockItem::where('order_item_id', $fulfillment->order_item_id)->sole()->secret);
        Http::assertSentCount(2);
    }

    private function paidSupplierOrder(int $quantity = 1): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate([
            'name' => 'Disposable independent review', 'provider' => 'aivault',
            'api_key' => 'independent-review-fake-key', 'currency' => 'USD',
            'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => 10_000_000, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'review-sku',
            'name' => 'Disposable SKU', 'unit_price_micros' => 1_000_000,
            'stock' => 10, 'orderable' => true, 'requires_input' => false,
            'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 798001]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        Http::assertNothingSent();
        app(OrderLifecycle::class)->applyManualCompletion($order);

        return [$order, SupplierFulfillment::where('order_id', $order->id)->sole()];
    }
}
