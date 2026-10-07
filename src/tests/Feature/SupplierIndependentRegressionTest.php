<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierFulfillment;
use App\Jobs\SendTelegramReceipt;
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
use App\Models\SupplierManualHandoff;
use App\Models\SupplierOrderHistoryReconciliation;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierOrderHistoryReconciliationService;
use App\Services\Telegram\TelegramAdminPaidOrderNotifier;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    public static function conflictingCommittedEconomics(): array
    {
        return [
            'changed actual debit' => [['quantity' => 1, 'total_cost' => '2']],
            'changed acquired quantity' => [['quantity' => 2, 'total_cost' => '1']],
        ];
    }

    #[DataProvider('conflictingCommittedEconomics')]
    public function test_recovery_cannot_rewrite_already_committed_economics(array $economics): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fakeSequence()
            ->push(['status' => 'pending', 'order_id' => 'committed-order', 'quantity' => 1, 'total_cost' => '1'], 201)
            ->push(array_merge(['status' => 'delivered', 'order_id' => 'committed-order', 'products' => ['conflicting-economic-key']], $economics));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertNotNull(SupplierPurchase::sole()->committed_at);
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'committed-order');
        $purchase = SupplierPurchase::sole();
        $this->assertSame(1_000_000, $purchase->actual_cost_micros);
        $this->assertSame(1, $purchase->observed_quantity);
        $this->assertSame(9_000_000, Supplier::sole()->balance_micros);
        $this->assertSame(0, Supplier::sole()->reserved_balance_micros);
        $this->assertSame('review', $purchase->state);
        $this->assertSame('economic_evidence_conflict', $purchase->last_error);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::count());
        Http::assertSentCount(2);
        $this->expectException(InvalidOrderTransition::class);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
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

    public function test_operator_can_record_external_handoff_without_allocating_or_sending_a_second_credential(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        $review = ManualFulfillmentReview::query()->where('order_id', $order->id)->sole();
        $owner = User::factory()->create(['role' => 'owner']);
        Queue::fake([SendTelegramReceipt::class]);

        $resolved = app(ManualFulfillmentResolutionService::class)->recordExternalHandoff(
            $review,
            $owner,
            $fulfillment->id,
            2,
            'Dua unit diserahkan langsung setelah pelanggan menghubungi support.',
        );

        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $resolved->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame('ambiguous', SupplierPurchase::sole()->fresh()->state);
        $this->assertSame(2_000_000, Supplier::sole()->fresh()->reserved_balance_micros);
        $this->assertSame(0, ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)->count());
        $this->assertDatabaseHas('supplier_manual_handoffs', [
            'supplier_fulfillment_id' => $fulfillment->id,
            'quantity' => 2,
            'actor_user_id' => $owner->id,
        ]);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        $this->actingAs($owner)->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Status pembayaran: paid')
            ->assertSee('Pengiriman pelanggan')
            ->assertSee('Selesai · diserahkan di luar bot')
            ->assertSee('Pembelian supplier')
            ->assertSee('ambiguous');
        $this->get('/admin?tab=penjualan')
            ->assertOk()
            ->assertSee('Pengiriman pelanggan:')
            ->assertSee('Pembelian supplier:')
            ->assertSee('ambigu');

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'status' => 'pending',
            'order_id' => 'confirmed-original-purchase',
            'service_id' => 'review-sku',
            'quantity' => 2,
            'total_cost' => '2',
        ]));
        app(SupplierFulfillmentService::class)->confirmDebit(
            $fulfillment->id,
            $owner,
            'confirmed-original-purchase',
            2,
            2_000_000,
            'Provider order history and exact-order GET confirm quantity and cost.',
        );
        app(SupplierFulfillmentService::class)->confirmDebit(
            $fulfillment->id, $owner, 'confirmed-original-purchase', 2, 2_000_000,
            'Provider order history and exact-order GET confirm quantity and cost.',
        );

        $this->assertSame(0, Supplier::sole()->fresh()->reserved_balance_micros);
        $this->assertSame(8_000_000, Supplier::sole()->fresh()->balance_micros);
        $this->assertNotNull(SupplierPurchase::sole()->fresh()->committed_at);
        $this->assertDatabaseHas('supplier_purchase_evidence', [
            'supplier_purchase_id' => SupplierPurchase::sole()->id,
            'kind' => 'debit_confirmed',
            'provider_reference' => 'confirmed-original-purchase',
        ]);
        $this->assertSame(0, ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)->count());
        $this->assertNull(app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh()));
    }

    public function test_explicit_no_debit_evidence_releases_an_ambiguous_hold_once_without_retrying_purchase(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $owner = User::factory()->create(['role' => 'owner']);

        $purchase = app(SupplierFulfillmentService::class)->confirmNoDebit(
            $fulfillment->id,
            $owner,
            'provider-case-565-1',
            'Provider support case explicitly confirms no order or debit.',
        );

        $this->assertSame('rejected', $purchase->state);
        $this->assertSame(0, Supplier::sole()->fresh()->reserved_balance_micros);
        $this->assertSame(0, SupplierCatalogItem::sole()->fresh()->reserved_quantity);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $service = app(SupplierFulfillmentService::class);
        $replay = $service->confirmNoDebit(
            $fulfillment->id,
            $owner,
            'provider-case-565-1',
            'Provider support case explicitly confirms no order or debit.',
        );
        $this->assertSame($purchase->id, $replay->id);
        $this->assertSame(1, SupplierPurchase::sole()->evidence()->where('kind', 'no_debit')->count());
        $this->assertDatabaseHas('supplier_purchase_evidence', [
            'supplier_purchase_id' => $purchase->id,
            'kind' => 'no_debit',
            'evidence_source' => 'provider_case',
            'actor_user_id' => $owner->id,
        ]);
        app(SupplierFulfillmentService::class)->assertRefundable($order);
        $this->addToAssertionCount(1);
        $this->assertSame(1, SupplierPurchase::query()->count());
    }

    public function test_partial_external_handoff_submission_replay_is_idempotent_and_does_not_overcount_units(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::query()->where('order_id', $order->id)->sole();
        $owner = User::factory()->create(['role' => 'owner']);
        $firstKey = (string) Str::uuid();
        $resolution = app(ManualFulfillmentResolutionService::class);
        $first = $resolution->recordExternalHandoff($review, $owner, $fulfillment->id, 1, 'Unit pertama diserahkan manual.', idempotencyKey: $firstKey);
        $replay = $resolution->recordExternalHandoff($review, $owner, $fulfillment->id, 1, 'Unit pertama diserahkan manual.', idempotencyKey: $firstKey);

        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $first->state);
        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $replay->state);
        $this->assertSame(1, SupplierManualHandoff::query()->where('supplier_fulfillment_id', $fulfillment->id)->count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);

        $resolution->recordExternalHandoff($review->fresh(), $owner, $fulfillment->id, 1, 'Unit kedua diserahkan manual.', idempotencyKey: (string) Str::uuid());

        $this->assertSame(2, SupplierManualHandoff::query()->where('supplier_fulfillment_id', $fulfillment->id)->count());
        $this->assertSame(2, (int) SupplierManualHandoff::query()->where('supplier_fulfillment_id', $fulfillment->id)->sum('quantity'));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(2_000_000, Supplier::sole()->fresh()->reserved_balance_micros);
    }

    public function test_original_recovery_after_partial_external_handoff_settles_economics_without_allocating_replacement_units(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(2);
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
        $owner = User::factory()->create(['role' => 'owner']);
        app(ManualFulfillmentResolutionService::class)->recordExternalHandoff($review, $owner, $fulfillment->id, 1, 'One unit handed over.');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'status' => 'delivered', 'order_id' => 'partial-handoff-original',
            'service_id' => 'review-sku', 'quantity' => 2, 'total_cost' => '2',
            'products' => ['original-A', 'original-B'],
        ]));
        app(SupplierFulfillmentService::class)->recoverOrder($fulfillment->id, 'partial-handoff-original');
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(0, Supplier::sole()->reserved_balance_micros);
        $this->assertNotNull(SupplierPurchase::sole()->committed_at);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    public function test_toolorax_complete_history_covering_dispatch_is_durable_no_debit_evidence(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $owner = User::factory()->create(['role' => 'owner']);
        $oldOrderAt = now()->subHour()->format('Y-m-d H:i:s');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () use ($oldOrderAt) {
            return Http::response(['ok' => true, 'count' => 1, 'orders' => [[
                'order_code' => 'old-provider-order', 'product_id' => 8, 'quantity' => 1,
                'total' => '1.000', 'status' => 'fulfilled', 'channel' => 'api', 'created_at' => $oldOrderAt,
            ]]]);
        });

        $reconciliation = app(SupplierOrderHistoryReconciliationService::class)->reconcile(
            $fulfillment->id,
            $owner,
            'Asia/Jakarta',
        );
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://shopbot.00969600.xyz/shop-api/v1/orders?limit=100'),
            json_encode(Http::recorded()->map(fn ($recorded) => [$recorded[0]->method(), $recorded[0]->url()])));

        $this->assertSame(SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER, $reconciliation->outcome, (string) $reconciliation->error_code);
        $this->assertSame(1, $reconciliation->row_count);
        $this->assertLessThanOrEqual($reconciliation->dispatch_at->getTimestamp(), $reconciliation->coverage_start->getTimestamp());
        $this->assertSame('Asia/Jakarta', $reconciliation->history_timezone);
        $this->assertNotNull($reconciliation->response_sha256);
        $this->assertSame([], $reconciliation->candidate_order_ids);

        $purchase = app(SupplierFulfillmentService::class)->confirmNoDebit(
            $fulfillment->id,
            $owner,
            '',
            'Complete ShopBot order history spans dispatch and contains no order for this SKU.',
            evidenceSource: 'provider_history',
            historyReconciliationId: $reconciliation->id,
        );

        $this->assertSame('rejected', $purchase->state);
        $this->assertSame(0, Supplier::sole()->fresh()->reserved_balance_micros);
        $this->assertDatabaseHas('supplier_purchase_evidence', [
            'supplier_purchase_id' => $purchase->id,
            'history_reconciliation_id' => $reconciliation->id,
            'evidence_source' => 'provider_history',
        ]);
        app(SupplierFulfillmentService::class)->assertRefundable($order);
    }

    public function test_toolorax_saturated_history_is_durable_but_cannot_release_hold(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $owner = User::factory()->create(['role' => 'owner']);
        $oldOrderAt = now()->subHour()->format('Y-m-d H:i:s');
        $orders = [];
        for ($index = 0; $index < SupplierClient::TOOL_ORAX_ORDER_HISTORY_LIMIT; $index++) {
            $orders[] = [
                'order_code' => 'history-order-'.$index,
                'product_id' => 8,
                'quantity' => 1,
                'total' => '1.000',
                'status' => 'fulfilled',
                'channel' => 'api',
                'created_at' => $oldOrderAt,
            ];
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'count' => count($orders), 'orders' => $orders]));

        $reconciliation = app(SupplierOrderHistoryReconciliationService::class)->reconcile($fulfillment->id, $owner, 'Asia/Jakarta');

        $this->assertSame(SupplierOrderHistoryReconciliation::OUTCOME_LIMIT_REACHED, $reconciliation->outcome, (string) $reconciliation->error_code);
        $this->assertSame('history_limit_reached', $reconciliation->error_code);
        $this->assertSame(100, $reconciliation->row_count);
        try {
            app(SupplierFulfillmentService::class)->confirmNoDebit(
                $fulfillment->id,
                $owner,
                '',
                'Attempt to use a truncated history result.',
                evidenceSource: 'provider_history',
                historyReconciliationId: $reconciliation->id,
            );
            $this->fail('A limit-reached history result must not release the purchase hold.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(1_000_000, Supplier::sole()->fresh()->reserved_balance_micros);
            $this->assertNull(SupplierPurchase::sole()->fresh()->committed_at);
        }
    }

    public function test_empty_history_cannot_establish_dispatch_coverage(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'count' => 0, 'orders' => []]));
        $owner = User::factory()->create(['role' => 'owner']);
        $scan = app(SupplierOrderHistoryReconciliationService::class)->reconcile($fulfillment->id, $owner, 'Asia/Jakarta');
        $this->assertSame('inconclusive', $scan->outcome);
        $this->assertSame('dispatch_outside_history_window', $scan->error_code);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
    }

    public function test_future_provider_timestamps_cannot_authorize_no_debit_with_an_unverified_clock_basis(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'count' => 2, 'orders' => [
            ['order_code' => 'older-history-row', 'product_id' => 8, 'quantity' => 1,
                'total' => '1', 'status' => 'fulfilled', 'created_at' => now()->subDay()->format('Y-m-d H:i:s')],
            ['order_code' => 'future-history-row', 'product_id' => 8, 'quantity' => 1,
                'total' => '1', 'status' => 'fulfilled', 'created_at' => now()->addHours(3)->format('Y-m-d H:i:s')],
        ]]));
        $owner = User::factory()->create(['role' => 'owner']);
        $scan = app(SupplierOrderHistoryReconciliationService::class)->reconcile($fulfillment->id, $owner, 'Asia/Jakarta');
        $this->assertSame('inconclusive', $scan->outcome);
        $this->assertSame('provider_clock_inconsistent', $scan->error_code);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
    }

    public function test_stale_operator_proof_cannot_release_a_different_current_purchase(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.operate', $fulfillment), [
                'action' => 'no_debit', 'purchase_id' => $purchase->id + 1,
                'proof_source' => 'provider_case', 'proof_reference' => 'case-old-purchase',
                'proof_note' => 'The provider confirmed an earlier purchase was not debited.',
            ])->assertStatus(409);
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
    }

    public function test_older_no_order_scan_cannot_override_a_newer_candidate_order_history(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $owner = User::factory()->create(['role' => 'owner']);
        $oldOrderAt = now()->subHour()->format('Y-m-d H:i:s');
        $candidateOrderAt = SupplierPurchase::sole()->dispatched_at->timezone('Asia/Jakarta')->addMinute()->format('Y-m-d H:i:s');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['ok' => true, 'count' => 1, 'orders' => [[
                'order_code' => 'old-order-before-dispatch', 'product_id' => 8, 'quantity' => 1,
                'total' => '1.000', 'status' => 'fulfilled', 'created_at' => $oldOrderAt,
            ]]])
            ->push(['ok' => true, 'count' => 1, 'orders' => [[
                'order_code' => 'candidate-after-dispatch', 'product_id' => 4, 'quantity' => 1,
                'total' => '1.000', 'status' => 'fulfilled', 'created_at' => $candidateOrderAt,
            ]]]);

        $noOrderScan = app(SupplierOrderHistoryReconciliationService::class)->reconcile($fulfillment->id, $owner, 'Asia/Jakarta');
        $this->travel(61)->seconds();
        $candidateScan = app(SupplierOrderHistoryReconciliationService::class)->reconcile($fulfillment->id, $owner, 'Asia/Jakarta');

        $this->assertSame(SupplierOrderHistoryReconciliation::OUTCOME_NO_ORDER, $noOrderScan->outcome);
        $this->assertSame(SupplierOrderHistoryReconciliation::OUTCOME_CANDIDATE_ORDERS, $candidateScan->outcome);
        $this->assertSame(['candidate-after-dispatch'], $candidateScan->candidate_order_ids);
        try {
            app(SupplierFulfillmentService::class)->confirmNoDebit(
                $fulfillment->id,
                $owner,
                '',
                'Attempt to use stale no-order scan.',
                evidenceSource: 'provider_history',
                historyReconciliationId: $noOrderScan->id,
            );
            $this->fail('A newer candidate history result must invalidate an older no-order conclusion.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(1_000_000, Supplier::sole()->fresh()->reserved_balance_micros);
            $this->assertNull(SupplierPurchase::sole()->fresh()->committed_at);
        }
    }

    public function test_debit_confirmation_requires_exact_get_quantity_and_cost_match(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'order' => [
            'order_code' => 'order-confirmed-debit', 'product_id' => 4, 'quantity' => 1,
            'total' => '1.000', 'status' => 'fulfilled', 'delivered_keys' => ['provider-unit'],
        ]]));

        try {
            app(SupplierFulfillmentService::class)->confirmDebit(
                $fulfillment->id,
                User::factory()->create(['role' => 'owner']),
                'order-confirmed-debit',
                2,
                1_000_000,
                'Operator values match the provider order history.',
            );
            $this->fail('A supplier GET with a different quantity must not settle the purchase.');
        } catch (InvalidOrderTransition) {
            $this->assertSame('ambiguous', SupplierPurchase::sole()->fresh()->state);
            $this->assertNull(SupplierPurchase::sole()->fresh()->committed_at);
            $this->assertSame(1_000_000, Supplier::sole()->fresh()->reserved_balance_micros);
            $this->assertSame(0, ProductStockItem::query()->count());
            $this->assertDatabaseMissing('supplier_purchase_evidence', ['kind' => 'debit_confirmed']);
        }
    }

    public function test_admin_paid_notification_states_payment_customer_delivery_and_supplier_purchase_separately(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        config()->set('services.telegram.token', 'test-token');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);

        app(TelegramAdminPaidOrderNotifier::class)->sendEffect($order, '123456');

        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, json_encode($recorded->map(fn ($pair) => [$pair[0]->url(), $pair[0]->data()])));
        $text = (string) ($recorded->first()[0]['text'] ?? '');
        $this->assertStringContainsString('Status pembayaran: Paid', $text, $text);
        $this->assertStringContainsString('Pengiriman pelanggan:', $text, $text);
        $this->assertStringContainsString('Perlu review operator', $text, $text);
        $this->assertStringContainsString('Pembelian supplier:', $text, $text);
        $this->assertStringContainsString('ambiguous', $text, $text);
        $this->assertStringNotContainsString('Produk terjual!', $text, $text);
    }

    public function test_completed_supplier_acquisition_without_receipt_does_not_claim_customer_delivery(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => Http::response([
            'status' => 'delivered', 'order_id' => 'acquired-but-not-sent',
            'service_id' => 'review-sku', 'quantity' => 1, 'total_cost' => '1',
            'products' => ['fixture-delivery'],
        ]));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get(route('admin.orders.show', $order))
            ->assertOk()->assertSee('Supplier fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review');
        $this->get('/admin?tab=penjualan')->assertOk()
            ->assertSee('supplier selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config()->set('services.telegram.token', 'test-token');
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        app(TelegramAdminPaidOrderNotifier::class)->sendEffect($order, '123456');
        $text = (string) Http::recorded()->sole()[0]['text'];
        $this->assertStringContainsString('Supplier fulfillment selesai · receipt Telegram belum terkonfirmasi; periksa Manual Delivery Review', $text);
    }

    public function test_read_only_order_roles_see_delivery_status_without_private_supplier_economics(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        foreach (['finance', 'support'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor)->get(route('admin.orders.show', $order))
                ->assertOk()->assertSee('Perlu review operator')
                ->assertDontSee('Disposable independent review')
                ->assertDontSee('Observasi wallet terakhir:')
                ->assertDontSee('biaya perkiraan')
                ->assertViewHas('canViewSupplierOperations', false);
        }
    }

    public function test_owner_dashboard_records_external_handoff_only_after_password_confirmation(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $review = ManualFulfillmentReview::query()->where('order_id', $order->id)->sole();
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.resolve', $review), [
                'action' => 'hand_off',
                'fulfillment_id' => $fulfillment->id,
                'handoff_quantity' => 1,
                'handoff_key' => (string) Str::uuid(),
                'reason' => 'Diserahkan langsung oleh operator setelah verifikasi pelanggan.',
            ])
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $review));

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertDatabaseHas('supplier_manual_handoffs', ['supplier_fulfillment_id' => $fulfillment->id, 'actor_user_id' => $owner->id]);
    }

    public function test_owner_dashboard_records_no_debit_evidence_before_releasing_hold(): void
    {
        [$order, $fulfillment] = $this->paidSupplierOrder();
        Http::fake(fn () => throw new ConnectionException('The supplier may have debited the request.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $owner = User::factory()->create(['role' => 'owner']);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.operate', $fulfillment), [
                'action' => 'no_debit',
                'purchase_id' => SupplierPurchase::sole()->id,
                'proof_source' => 'provider_statement',
                'proof_reference' => 'history-window-2026-10-06T20:55+08:00',
                'proof_note' => 'The provider history covers the dispatch time and has no matching order.',
            ])
            ->assertRedirect(route('admin.suppliers.purchases'));

        $this->assertSame('rejected', SupplierPurchase::sole()->fresh()->state);
        $this->assertSame(0, Supplier::sole()->fresh()->reserved_balance_micros);
        $this->assertDatabaseHas('supplier_purchase_evidence', ['kind' => 'no_debit', 'actor_user_id' => $owner->id]);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    public function test_admin_supplier_dashboard_distinguishes_last_wallet_observation_from_catalog_sync(): void
    {
        $this->paidSupplierOrder();
        $supplier = Supplier::sole();
        $observedAt = now()->subMinutes(10);
        $supplier->forceFill([
            'balance_micros' => 5_000_000,
            'reserved_balance_micros' => 1_000_000,
            'balance_observed_at' => $observedAt,
            'synced_at' => now(),
            'sync_expires_at' => now()->addMinutes(5),
        ])->save();

        $connection = app(AdminSupplierDashboardData::class)->forTab()['connections']->sole();

        $this->assertSame($observedAt->toIso8601String(), $connection['balance_observed_at']);
        $this->assertTrue($connection['balance_uncertain']);
        $this->assertTrue($connection['has_unresolved_hold']);
        $this->assertSame('4.000000', $connection['available_balance']);
    }

    public function test_recovery_only_enqueues_non_buymail_purchase_without_scheduler_transport(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => Http::response(['ok' => false], 500));
        Queue::fake();

        $this->assertSame(['processed' => 1], app(SupplierFulfillmentService::class)->recover());

        Http::assertNothingSent();
        $this->assertSame('pending', $fulfillment->fresh()->state);
        $this->assertSame(0, SupplierPurchase::count());
        Queue::assertPushed(ProcessSupplierFulfillment::class, fn ($job) => $job->fulfillmentId === $fulfillment->id);
    }

    public function test_pre_transport_crash_can_resume_non_idempotent_purchase_without_guessing(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        $purchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => Supplier::sole()->id,
            'supplier_catalog_item_id' => SupplierCatalogItem::sole()->id, 'reference' => (string) Str::uuid(),
            'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000,
            'state' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond(),
        ]);
        $fulfillment->update(['state' => 'purchasing', 'current_purchase_id' => $purchase->id, 'next_attempt_at' => now()->subSecond()]);
        Http::fake(function () use ($purchase) {
            $this->assertNotNull($purchase->fresh()->dispatched_at);
            $this->assertDatabaseHas('supplier_purchase_dispatches', [
                'supplier_purchase_id' => $purchase->id, 'claim_token' => $purchase->fresh()->claim_token, 'outcome' => 'started',
            ]);

            return Http::response(['ok' => true, 'order' => ['order_code' => 'resumed-order', 'status' => 'fulfilled',
                'product_id' => 4, 'quantity' => 1, 'total' => '1.000', 'delivered_keys' => ['resumed-key']]]);
        });

        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::assertSentCount(1);
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(0, $purchase->fresh()->replay_count);
        $this->assertSame(OrderStatus::Completed, $fulfillment->fresh()->order->order_status);
        $this->assertSame('resumed-key', ProductStockItem::sole()->secret);
    }

    public function test_automatic_unknown_outcome_history_reads_stop_after_three_without_releasing_hold(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'count' => 0, 'orders' => []]));
        for ($attempt = 0; $attempt < 4; $attempt++) {
            app(SupplierFulfillmentService::class)->recover();
            (new ProcessSupplierFulfillment($fulfillment->id))->handle(app(SupplierFulfillmentService::class));
            $this->travel(5)->minutes();
        }
        $this->travelBack();

        Http::assertSentCount(3);
        $this->assertSame(3, $purchase->historyReconciliations()->whereNull('actor_user_id')->count());
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
        $this->assertNull($fulfillment->fresh()->next_attempt_at);
        $this->assertSame(['processed' => 0], app(SupplierFulfillmentService::class)->recover());
    }

    public function test_automatic_history_with_unqualified_provider_timezone_cannot_authorize_no_debit(): void
    {
        [, $fulfillment] = $this->paidSupplierOrder(provider: 'toolorax');
        Http::fake(fn () => throw new ConnectionException('Possible debit.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['ok' => true, 'count' => 1, 'orders' => [[
            'order_code' => 'old-unrelated-order', 'product_id' => 8, 'quantity' => 1, 'total' => '1.000',
            'status' => 'fulfilled', 'created_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]]]));
        (new ProcessSupplierFulfillment($fulfillment->id))->handle(app(SupplierFulfillmentService::class));
        $history = SupplierPurchase::sole()->historyReconciliations()->sole();
        $this->assertSame('inconclusive', $history->outcome);
        $this->assertSame('history_timezone_unverified', $history->error_code);
        $this->assertNull($history->coverage_start);
        $this->assertSame(1_000_000, Supplier::sole()->reserved_balance_micros);
        $this->expectException(InvalidOrderTransition::class);
        app(SupplierFulfillmentService::class)->confirmNoDebit(
            $fulfillment->id, User::factory()->create(['role' => 'owner']), '', 'No qualified timezone.',
            evidenceSource: 'provider_history', historyReconciliationId: $history->id,
        );
    }

    private function paidSupplierOrder(int $quantity = 1, string $provider = 'aivault'): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate([
            'name' => 'Disposable independent review', 'provider' => $provider,
            'api_key' => 'independent-review-fake-key', 'currency' => $provider === 'prodseller' ? 'USDT' : 'USD',
            'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => 10_000_000, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => $provider === 'toolorax' ? '4' : 'review-sku',
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
