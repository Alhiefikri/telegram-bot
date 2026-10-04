<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

class SupplierFulfillmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_local_first_mixed_order_remains_paid_processing_until_complete_private_delivery(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(local: 1, quantity: 3);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'local-owned-account']);
        $order = $this->checkout($cart);
        $item = $order->orderItems->sole();
        $this->assertSame(1, $item->localQuantity());
        $this->assertSame(2, $item->supplier_quantity);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(2_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        Http::assertNothingSent();
        $paid = app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->assertSame(OrderStatus::Processing, $paid->order->order_status);
        $this->assertSame(PaymentStatus::Paid, $paid->order->payment_status);
        $this->assertFalse($paid->receiptEligible);
        $replayedApproval = app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->assertFalse($replayedApproval->newlyApplied);
        $this->assertFalse($replayedApproval->receiptEligible);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($paid->order, $order->customer));
        $this->assertNull(app(TelegramOutboundEffectService::class)->recordReceipt($paid->order));
        $this->assertSame(-3, InventoryMovement::where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->sole()->quantity);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'supplier-order-1', 'quantity' => 2, 'total_cost' => '2', 'deliveredKeys' => ['external-a', 'external-b']]));
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        app(SupplierFulfillmentService::class)->process($f->id);
        Http::assertSentCount(1);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(3, $item->stockItems()->count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(8_000_000, $supplier->fresh()->balance_micros);
        $this->assertTrue(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), Customer::factory()->create()));
        $this->assertStringNotContainsString('external-a', DB::table('product_stock_items')->whereNotNull('supplier_purchase_id')->first()->secret);
        $this->assertArrayNotHasKey('sources_snapshot', $f->fresh()->toArray());
        $this->assertArrayNotHasKey('supplier_quantity', $item->toArray());
        $publicOrder = $order->fresh()->load([
            'orderItems.product.supplierSources.catalogItem.supplier',
            'orderItems.product.supplierPricePolicy',
            'orderItems.supplierFulfillment.purchases.supplier',
            'orderItems.stockItems.supplierPurchase.supplier',
        ])->toJson();
        foreach (['Private connection', 'fixture-secret', 'supplier_purchase_id', 'supplier_unit_index', 'expected_cost_micros', 'external-a'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $publicOrder);
        }
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNull('retired_at')->count());
        $this->assertSame(0, $product->fresh()->localAvailableStock());
    }

    public function test_non_idempotent_ambiguous_purchase_is_never_retried_and_refund_cannot_release_hold(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(provider: 'warzone');
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn () => Http::response(['message' => 'private provider failure'], 500));
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        app(SupplierFulfillmentService::class)->recover();
        Http::assertSentCount(1);
        $this->assertSame('review', $f->fresh()->state);
        $this->assertSame('ambiguous', SupplierPurchase::sole()->state);
        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
            $this->fail('Unknown supplier debit must block refund.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
            $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
            $this->assertSame(1, $sku->fresh()->reserved_quantity);
        }
    }

    public function test_partial_delivery_is_encrypted_review_evidence_not_complete_or_repurchase(): void
    {
        [, $supplier, , $cart] = $this->fixture(quantity: 2);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'partial-order', 'quantity' => 2, 'total_cost' => '2', 'deliveredKeys' => ['partial-private-key']]));
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        app(SupplierFulfillmentService::class)->process($f->id);
        Http::assertSentCount(1);
        $this->assertSame('review', $f->fresh()->state);
        $this->assertSame(['partial-private-key'], SupplierPurchase::sole()->delivery_payload);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(8_000_000, $supplier->fresh()->balance_micros);
        $this->assertFalse(app(CredentialDeliveryEligibility::class)->allows($order->fresh()));
    }

    public function test_known_order_recovery_only_reads_and_allocates_complete_matching_delivery_once(): void
    {
        [, , , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fakeSequence()->push(['status' => 'pending', 'orderId' => 'known-order', 'quantity' => 1, 'total_cost' => '1'])->push(['status' => 'delivered', 'orderId' => 'known-order', 'deliveredKeys' => ['recovered-key']]);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        $this->travel(2)->minutes();
        app(SupplierFulfillmentService::class)->recover();
        $this->travelBack();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/orders/known-order'));
        $this->assertSame('delivered', $f->fresh()->state);
        $this->assertSame(1, ProductStockItem::count());
        $this->assertSame('recovered-key', ProductStockItem::sole()->secret);
    }

    public function test_delivery_without_actual_cost_retains_hold_until_metadata_only_recovery(): void
    {
        [, $supplier, , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fakeSequence()
            ->push(['status' => 'delivered', 'orderId' => 'economic-recovery', 'quantity' => 1, 'deliveredKeys' => ['retained-private-key']])
            ->push(['status' => 'delivered', 'orderId' => 'economic-recovery', 'total_cost' => '1']);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        $this->assertSame('awaiting_delivery', $f->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, ProductStockItem::count());
        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
            $this->fail('Unobserved actual debit must block refund.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        }
        $this->travel(2)->minutes();
        app(SupplierFulfillmentService::class)->recover();
        $this->travelBack();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/orders/economic-recovery'));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('retained-private-key', ProductStockItem::sole()->secret);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
    }

    public function test_catalog_sync_during_generic_purchase_does_not_apply_the_same_wallet_debit_twice(): void
    {
        [, $supplier, , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(function ($request) use ($supplier) {
            $this->assertSame(0, DB::transactionLevel());
            if ($request->method() === 'GET') {
                return Http::response(str_ends_with($request->url(), '/balance')
                    ? ['balance' => '9', 'currency' => 'USDT']
                    : ['products' => [['id' => 'fixture-sku', 'name' => 'Private', 'price' => '1', 'stock' => 9]], 'currency' => 'USDT']);
            }
            $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier->fresh()));

            return Http::response(['status' => 'delivered', 'orderId' => 'sync-during-purchase', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['single-debit-key']]);
        });
        app(SupplierFulfillmentService::class)->process(SupplierFulfillment::where('order_id', $order->id)->sole()->id);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('single-debit-key', ProductStockItem::sole()->secret);
        Http::assertSentCount(3);
    }

    public function test_metadata_omission_cannot_erase_a_previously_observed_quantity_mismatch(): void
    {
        [, $supplier, , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fakeSequence()
            ->push(['status' => 'delivered', 'orderId' => 'quantity-mismatch', 'quantity' => 2, 'total_cost' => '2', 'deliveredKeys' => ['mismatched-private-key']])
            ->push(['status' => 'delivered', 'orderId' => 'quantity-mismatch', 'total_cost' => '2', 'deliveredKeys' => ['mismatched-private-key']]);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        app(SupplierFulfillmentService::class)->recoverOrder($f->id, 'quantity-mismatch');
        app(SupplierFulfillmentService::class)->process($f->id);
        $this->assertSame(2, SupplierPurchase::sole()->observed_quantity);
        $this->assertSame('review', $f->fresh()->state);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
    }

    public function test_recovering_original_debit_never_reopens_a_manually_delivered_customer_obligation(): void
    {
        [, $supplier, , $cart] = $this->fixture(provider: 'warzone');
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fakeSequence()
            ->push(['message' => 'unknown purchase outcome'], 500)
            ->push(['status' => 'pending', 'order_id' => 'original-order', 'quantity' => 1])
            ->push(['status' => 'pending', 'order_id' => 'original-order', 'quantity' => 1, 'amount' => '1']);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        app(SupplierFulfillmentService::class)->provideManualCredentials($f->id, ['owner-replacement-key']);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $this->get(route('admin.suppliers.purchases'))->assertOk()
            ->assertViewHas('fulfillments', fn ($rows) => $rows->contains('id', $f->id))
            ->assertDontSee('owner-replacement-key');

        app(SupplierFulfillmentService::class)->recoverOrder($f->id, 'original-order');
        $this->assertSame('delivered', $f->fresh()->state);
        $this->assertTrue(app(CredentialDeliveryEligibility::class)->allows($order->fresh(), $order->customer));
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.operate', $f), ['action' => 'recover', 'supplier_order_id' => 'original-order'])
            ->assertRedirect(route('admin.suppliers.purchases'));
        $this->assertSame('settled', SupplierPurchase::sole()->state);
        $this->assertSame('delivered', $f->fresh()->state);
        $this->assertSame('owner-replacement-key', ProductStockItem::sole()->secret);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->get(route('admin.suppliers.purchases'))->assertOk()
            ->assertViewHas('fulfillments', fn ($rows) => ! $rows->contains('id', $f->id));
        Http::assertSentCount(3);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
        $this->assertSame(PaymentStatus::Refunded, $order->fresh()->payment_status);
        $this->assertSame(0, ProductStockItem::whereNull('retired_at')->count());
    }

    public function test_cancelled_unpaid_supplier_order_releases_capacity_and_late_paid_never_buys(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyPendingCancellation($order);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        app(OrderLifecycle::class)->applyPaymentPaid($order->payments()->sole());
        app(SupplierFulfillmentService::class)->recover();
        Http::assertNothingSent();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(0, $product->fresh()->stock);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->provideManualCredentials($f->id, ['owner-supplied-late-key']);
        Http::assertNothingSent();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame('owner-supplied-late-key', ProductStockItem::sole()->secret);
        $this->assertTrue(app(CredentialDeliveryEligibility::class)->allows($order->fresh()));
    }

    public function test_stale_non_idempotent_claim_is_held_without_a_second_post(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(provider: 'warzone');
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id, 'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000, 'state' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute(), 'dispatched_at' => now()->subMinutes(3)]);
        $f->update(['state' => 'purchasing', 'current_purchase_id' => $purchase->id, 'next_attempt_at' => now()->subMinute()]);
        app(SupplierFulfillmentService::class)->recover();
        Http::assertNothingSent();
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
    }

    public function test_documented_idempotent_stale_dispatch_replays_only_the_original_reference_once(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $reference = (string) Str::uuid();
        $purchase = SupplierPurchase::create(['supplier_fulfillment_id' => $f->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id, 'reference' => $reference, 'quantity' => 1, 'expected_cost_micros' => 1_000_000, 'exchange_rate_idr' => 1000, 'state' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subMinute(), 'dispatched_at' => now()->subMinutes(3)]);
        $f->update(['state' => 'purchasing', 'current_purchase_id' => $purchase->id, 'next_attempt_at' => now()->subMinute()]);
        Http::fake(fn () => Http::response(['status' => 'delivered', 'orderId' => 'replayed-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['original-reference-delivery']]));
        app(SupplierFulfillmentService::class)->recover();
        app(SupplierFulfillmentService::class)->recover();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === [$reference]);
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(1, $purchase->fresh()->replay_count);
        $this->assertSame('original-reference-delivery', ProductStockItem::sole()->secret);
    }

    public function test_only_definitive_no_debit_rejection_can_fallback_to_a_snapshotted_equivalent_source(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(provider: 'warzone');
        $alternative = $supplier->replicate();
        $alternative->api_key = 'alternative-fixture-key';
        $alternative->save();
        $secondSku = $sku->replicate();
        $secondSku->supplier_id = $alternative->id;
        $secondSku->save();
        ProductSupplierSource::create(['product_id' => $cart->cartItems()->sole()->product_id, 'supplier_catalog_item_id' => $secondSku->id, 'is_active' => true, 'priority' => 1]);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fakeSequence()->push(['message' => 'insufficient wallet'], 400)->push(['status' => 'delivered', 'orderId' => 'fallback-order', 'quantity' => 1, 'total_cost' => '1', 'delivered_products' => ['equivalent-delivery']]);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($f->id);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1_000_000, $alternative->fresh()->reserved_balance_micros);
        $this->travel(1)->minutes();
        app(SupplierFulfillmentService::class)->recover();
        $this->travelBack();
        Http::assertSentCount(2);
        $this->assertSame('delivered', $f->fresh()->state);
        $this->assertSame('equivalent-delivery', ProductStockItem::sole()->secret);
        $this->assertSame($alternative->id, ProductStockItem::sole()->supplierPurchase->supplier_id);
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(9_000_000, $alternative->fresh()->balance_micros);
    }

    public function test_store_balance_refund_guard_precedes_any_credit_while_purchase_is_in_flight(): void
    {
        [, $supplier, , $cart] = $this->fixture();
        $customer = $cart->customer;
        app(StoreBalanceLedger::class)->credit($customer, 10000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', 'fixture-credit', 'fixture-credit:once');
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::storeBalance());
        $this->assertSame(5000, CustomerBalanceAccount::where('customer_id', $customer->id)->value('balance'));
        $blocked = false;
        Http::fake(function () use ($order, &$blocked) {
            try {
                app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
            } catch (InvalidOrderTransition) {
                $blocked = true;
            }

            return Http::response(['status' => 'delivered', 'orderId' => 'balance-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['balance-owned-key']]);
        });
        app(SupplierFulfillmentService::class)->process(SupplierFulfillment::where('order_id', $order->id)->sole()->id);
        $this->assertTrue($blocked);
        $this->assertSame(5000, CustomerBalanceAccount::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
        app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
        $this->assertSame(10000, CustomerBalanceAccount::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(0, ProductStockItem::whereNull('retired_at')->count());
    }

    public function test_toolorax_disputed_exact_order_debit_retains_hold_without_allocating_delivery(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture(provider: 'toolorax');
        $sku->update(['external_id' => '4']);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn ($request) => Http::response($request->method() === 'POST'
            ? ['ok' => true, 'order_code' => 'order-conflict', 'quantity' => 1, 'total' => 1, 'delivered_keys' => ['PRIVATE-UNIT']]
            : ['ok' => true, 'order' => ['order_code' => 'order-conflict', 'product_id' => 4, 'quantity' => 1, 'total' => 1.1, 'status' => 'fulfilled', 'delivered_keys' => ['PRIVATE-UNIT']]]));
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = $fulfillment->purchases()->sole();
        $this->assertSame('order-conflict', $purchase->supplier_order_id);
        $this->assertNull($purchase->committed_at);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $order->orderItems->sole()->stockItems()->count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        Http::assertSentCount(2);
    }

    protected function fixture(int $local = 0, int $quantity = 1, string $provider = 'prodseller'): array
    {
        $product = Product::factory()->create(['stock' => $local, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate(['name' => 'Private connection', 'provider' => $provider, 'api_key' => 'fixture-secret', 'currency' => $provider === 'prodseller' ? 'USDT' : 'USD', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 10_000_000, 'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $sku = SupplierCatalogItem::create(['supplier_id' => $supplier->id, 'external_id' => 'fixture-sku', 'name' => 'Private supplier SKU', 'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => []]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'buffer_idr' => 0, 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customer = Customer::factory()->create(['telegram_id' => 790001]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return [$product, $supplier, $sku, $cart];
    }

    protected function checkout(Cart $cart): Order
    {
        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    }
}
