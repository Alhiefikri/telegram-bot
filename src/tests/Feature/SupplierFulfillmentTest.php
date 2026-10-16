<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\ProcessSupplierFulfillment;
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
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
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

    public function test_api_replay_and_lost_queue_recovery_preserve_an_unknown_supplier_purchase(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(provider: 'warzone');
        $customer = $cart->customer;
        app(StoreBalanceLedger::class)->creditTopUp($customer, 10_000, 'api-supplier-funding', 'api-supplier-funding');
        $credentials = app(CustomerApiCredentials::class);
        $principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
        $http = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'product_id' => (string) $product->id, 'quantity' => 1,
            'preference' => 'idr_only', 'expected_totals' => ['IDR' => '5000'],
        ], JSON_THROW_ON_ERROR));
        $http->headers->set('Idempotency-Key', 'api-supplier-unknown');
        $request = CustomerApiOrderRequest::fromRequest($http);
        $checkout = app(CustomerApiOrderCheckout::class);
        $order = $checkout->place($principal, $request)->order;
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $this->assertSame('pending', $fulfillment->state);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        Http::assertNothingSent();

        // Drop all wakeups, then rediscover the committed obligation.
        Queue::fake();
        $service = app(SupplierFulfillmentService::class);
        $service->recover();
        Queue::assertPushed(ProcessSupplierFulfillment::class);
        Http::fake(function () {
            $this->assertSame(0, DB::transactionLevel(), 'Supplier IO must be outside financial locks.');

            return Http::response(['message' => 'unknown purchase outcome'], 500);
        });
        $service->process($fulfillment->id);
        $service->recover();
        $service->process($fulfillment->id);
        $replay = $checkout->place($principal, $request);

        $this->assertTrue($replay->replayed);
        $this->assertSame($order->id, $replay->order->id);
        Http::assertSentCount(1);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame('ambiguous', SupplierPurchase::sole()->state);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, ProductStockItem::count());
        try {
            app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());
            $this->fail('Unknown supplier purchase permitted a refund.');
        } catch (InvalidOrderTransition) {
            $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
            $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        }
    }

    public function test_supplier_completion_without_materialized_receipt_is_recovered_without_reallocating(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        config()->set('services.telegram.token', 'test-token');
        $messageId = 900000;
        Http::fake(function ($request) use (&$messageId) {
            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true, 'result' => ['message_id' => ++$messageId]]);
            }

            return Http::response([
                'status' => 'delivered',
                'orderId' => 'supplier-receipt-recovery',
                'quantity' => 1,
                'total_cost' => '1',
                'deliveredKeys' => ['original-supplier-credential'],
            ]);
        });

        $fulfillment = SupplierFulfillment::query()->where('order_id', $order->id)->sole();
        app(SupplierFulfillmentService::class)->process($fulfillment->id);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame(0, $order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->count());
        $this->assertSame(1, $fulfillment->purchases()->count());
        $this->assertSame(1, $fulfillment->orderItem->stockItems()->count());
        Http::assertSentCount(1);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $receiptEffects = $order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS);
        $this->assertNotNull($receiptEffects->first());
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->getKey()),
            $receiptEffects->first()->effect_group_key,
        );
        $effectCountAfterRecovery = $receiptEffects->count();

        $groupKey = (string) $receiptEffects->first()->effect_group_key;
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $effect = $ledger->nextReceiptEffect($groupKey);
        while ($effect !== null) {
            $pipeline->deliver($effect, dispatchNext: false);
            $effect = $ledger->nextReceiptEffect($groupKey, (int) $effect->sequence);
        }

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame(
            [TelegramOutboundEffectState::Sent],
            $receiptEffects->orderBy('sequence')->pluck('state')->unique()->values()->all(),
        );
        $containsCredential = function (mixed $value) use (&$containsCredential): bool {
            if (is_string($value)) {
                return str_contains($value, 'original-supplier-credential');
            }
            if (! is_array($value)) {
                return false;
            }
            foreach ($value as $nestedValue) {
                if ($containsCredential($nestedValue)) {
                    return true;
                }
            }

            return false;
        };
        $credentialSent = Http::recorded(fn ($request): bool => $containsCredential($request->data()));
        $this->assertNotEmpty($credentialSent);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $this->assertSame($effectCountAfterRecovery, $receiptEffects->count());
        $this->assertSame(1, $fulfillment->purchases()->count());
        $this->assertSame(1, $fulfillment->orderItem->stockItems()->count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
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
        app(SupplierFulfillmentService::class)->process($f->id);
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
        app(SupplierFulfillmentService::class)->process($f->id);
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
        app(SupplierFulfillmentService::class)->process($f->id);
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
        app(SupplierFulfillmentService::class)->process($f->id);
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
        app(SupplierFulfillmentService::class)->process($f->id);
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

    public function test_nastele_ambiguous_purchase_keeps_holds_and_exact_get_recovers_once_at_fractional_rate(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $product->update(['price_amount' => 50000]);
        $supplier->forceFill(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001', 'balance_micros' => 300_000_000_000])->save();
        $sku->update(['external_id' => '43', 'currency' => 'VND', 'unit_price_micros' => 30_000_000_000]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        Http::fake(fn () => Http::response(['success' => false], 500));
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();
        $service = app(SupplierFulfillmentService::class);
        $service->process($f->id);
        $service->process($f->id);
        $service->recover();
        Http::assertSentCount(1);
        $this->assertSame('ambiguous', SupplierPurchase::sole()->state);
        $this->assertSame(30_000_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, ProductStockItem::whereNotNull('supplier_purchase_id')->count());
        Http::swap(new Factory);
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());

            return Http::response(['success' => true, 'data' => [
                'orderId' => 2244, 'status' => 'completed', 'totalAmount' => 30000,
                'items' => [['quantity' => 1, 'unitPrice' => 30000, 'accounts' => ['synthetic-nastele|password']]],
            ]]);
        });
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $service->recoverOrder($f->id, '2244', $owner);
        $service->process($f->id);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(270_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, ProductStockItem::whereNotNull('supplier_purchase_id')->count());
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(19501, SupplierMoney::costIdr(
            SupplierPurchase::sole()->actual_cost_micros, SupplierPurchase::sole()->exchange_rate_idr,
        ));
        Http::assertSentCount(1);
    }

    public function test_nastele_owner_funding_rate_purchase_allocates_credentials_once_with_exact_idr_cost(): void
    {
        [$product, $supplier, $sku, $cart] = $this->nasteleFixture();

        // Phase 1: the funded connection was observed, never hand-set.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/balance'));
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/products'));
        $this->assertSame(300_000_000_000, $supplier->balance_micros);
        $this->assertSame(69_000_000_000, $sku->unit_price_micros);
        $this->assertSame(4, $sku->stock);
        $this->assertTrue($sku->orderable);
        $this->assertFalse($sku->requires_input);

        $order = $this->checkout($cart);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();

        // Reservation is local: one wallet hold and one SKU hold for one 69.000 VND unit.
        Http::assertSentCount(2);
        $this->assertSame('reserved', $f->state);
        $this->assertSame(1, $f->quantity);
        $this->assertSame(69_000_000_000, $f->reserved_cost_micros);
        $this->assertSame(49500, $f->maximum_cost_idr);
        $this->assertSame(69_000_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->reserved_stock);

        $paid = app(OrderLifecycle::class)->applyManualCompletion($order);
        $this->assertSame(PaymentStatus::Paid, $paid->order->payment_status);
        $this->assertSame(OrderStatus::Processing, $paid->order->order_status);
        Http::assertSentCount(2);

        Http::swap(new Factory);
        $this->fakeNasteleOrders(7911, null, ['nastele-vnd-43@example.test | OwnerFunding-47770'], ['nastele-vnd-43@example.test | OwnerFunding-47770']);
        app(SupplierFulfillmentService::class)->process($f->id);

        // Phase 2: exactly one purchase POST and no fallback dispatch.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'));
        Http::assertSent(fn ($request) => $request->data()['productId'] === 43 && $request->data()['qty'] === 1);
        $this->assertSame(1, $f->fresh()->purchases()->count());
        $purchase = SupplierPurchase::sole();
        $this->assertSame('delivered', $purchase->state);
        $this->assertNotNull($purchase->committed_at);
        $this->assertSame(69_000_000_000, $purchase->actual_cost_micros);
        $this->assertSame('0.692308', $purchase->exchange_rate_idr);
        // Exact evidence: ceil(69.000 VND x 0.692308) = 47770 IDR, not 69.000 x 18.000.
        $this->assertSame(47770, SupplierMoney::costIdr($purchase->actual_cost_micros, $purchase->exchange_rate_idr));
        $this->assertLessThanOrEqual($f->maximum_cost_idr, 47770);

        // The credential is allocated exactly once and owned by this order.
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(1, ProductStockItem::count());
        $stockItem = ProductStockItem::sole();
        $this->assertSame('nastele-vnd-43@example.test | OwnerFunding-47770', $stockItem->secret);
        $this->assertSame($f->order_item_id, $stockItem->order_item_id);
        $this->assertSame($purchase->id, $stockItem->supplier_purchase_id);
        $this->assertSame($order->id, $stockItem->orderItem->order_id);
        $this->assertSame(1, $f->fresh()->orderItem->stockItems()->count());

        // The provider currency wallet is debited exactly once and the SKU hold released.
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(231_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(3, $sku->fresh()->stock);
    }

    public function test_nastele_unconfirmed_create_keeps_holds_and_exact_get_recovers_without_a_second_post(): void
    {
        [$product, $supplier, $sku, $cart] = $this->nasteleFixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $f = SupplierFulfillment::where('order_id', $order->id)->sole();

        Http::swap(new Factory);
        $this->fakeNasteleOrders(7911, 'pending', [], ['nastele-vnd-43@example.test | OwnerFunding-47770']);
        app(SupplierFulfillmentService::class)->process($f->id);

        // The create response proved neither delivery nor debit, so every hold stays.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/orders'));
        $this->assertSame(1, SupplierPurchase::count());
        $purchase = SupplierPurchase::sole();
        $this->assertSame('7911', $purchase->supplier_order_id);
        $this->assertSame('awaiting_delivery', $purchase->state);
        $this->assertNull($purchase->committed_at);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertSame(69_000_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame('awaiting_delivery', $f->fresh()->state);

        $this->travel(2)->minutes();
        app(SupplierFulfillmentService::class)->recover();
        app(SupplierFulfillmentService::class)->process($f->id);
        $this->travelBack();

        // Recovery read the exact order; the create was never replayed.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/orders/7911'));
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST')->count());
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame('delivered', $purchase->fresh()->state);
        $this->assertSame(69_000_000_000, $purchase->fresh()->actual_cost_micros);
        $this->assertSame(47770, SupplierMoney::costIdr($purchase->fresh()->actual_cost_micros, $purchase->fresh()->exchange_rate_idr));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(1, ProductStockItem::count());
        $this->assertSame('nastele-vnd-43@example.test | OwnerFunding-47770', ProductStockItem::sole()->secret);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(231_000_000_000, $supplier->fresh()->balance_micros);
    }

    /**
     * Synthetic Nastele VND connection funded through the real catalog sync:
     * SKU 43 at 69.000 VND, an observed wallet of 300.000 VND and the owner
     * funding rate 0.692308 IDR per VND.
     *
     * @return array{0: Product, 1: Supplier, 2: SupplierCatalogItem, 3: Cart}
     */
    private function nasteleFixture(): array
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $product->update(['price_amount' => 50000]);
        $supplier->forceFill(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.692308', 'balance_micros' => 0])->save();
        $sku->update(['external_id' => '43', 'currency' => 'VND']);
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/balance')) {
                return Http::response(['success' => true, 'data' => ['balance' => 300_000, 'currency' => 'VND']]);
            }
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/products')) {
                return Http::response(['success' => true, 'data' => [[
                    'id' => 43, 'name' => 'Nastele VND account', 'price' => 69000, 'stock' => 4, 'currency' => 'VND',
                ]]]);
            }

            return Http::response(['success' => false], 405);
        });
        $this->assertTrue(app(SupplierCatalogSync::class)->sync($supplier->fresh()));

        return [$product->fresh(), $supplier->fresh(), $sku->fresh(), $cart];
    }

    /**
     * @param  list<string>  $createAccounts
     * @param  list<string>  $recoveredAccounts
     */
    private function fakeNasteleOrders(int $orderId, ?string $createStatus, array $createAccounts, array $recoveredAccounts): void
    {
        Http::fake(function ($request) use ($orderId, $createStatus, $createAccounts, $recoveredAccounts) {
            if ($request->method() === 'POST') {
                $created = ['orderId' => $orderId, 'totalAmount' => 69000, 'items' => [['quantity' => 1, 'unitPrice' => 69000, 'accounts' => $createAccounts]]];
                if ($createStatus !== null) {
                    $created['status'] = $createStatus;
                }

                return Http::response(['success' => true, 'data' => $created], 201);
            }

            return Http::response(['success' => true, 'data' => [
                'orderId' => $orderId, 'status' => 'completed', 'totalAmount' => 69000,
                'items' => [['quantity' => 1, 'unitPrice' => 69000, 'accounts' => $recoveredAccounts]],
            ]]);
        });
    }

    protected function fixture(int $local = 0, int $quantity = 1, string $provider = 'prodseller'): array
    {
        $product = Product::factory()->create(['stock' => $local, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate(['name' => 'Private connection', 'provider' => $provider, 'api_key' => 'fixture-secret', 'currency' => $provider === 'prodseller' ? 'USDT' : 'USD', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 10_000_000, 'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $sku = SupplierCatalogItem::create(['supplier_id' => $supplier->id, 'external_id' => 'fixture-sku', 'name' => 'Private supplier SKU', 'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => []]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'minimum_profit_idr' => 500, 'rounding_idr' => 1]);
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
