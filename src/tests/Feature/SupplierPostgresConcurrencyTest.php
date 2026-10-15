<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseEvidence;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierExchangeRate;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierOrderHistoryReconciliationService;
use App\Services\Suppliers\SupplierPricing;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;
use Throwable;

#[Group('concurrency')]
final class SupplierPostgresConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || env('RUN_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Supplier races require the isolated PostgreSQL concurrency lane and pcntl.');
        }
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Http::preventStrayRequests();
    }

    #[Group('postgres-release')]
    public function test_two_workers_purchase_one_durable_intent_and_allocate_one_customer_delivery(): void
    {
        [$product, $supplier, , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $id = SupplierFulfillment::where('order_id', $order->id)->sole()->id;
        $results = $this->race([
            fn () => $this->process($id),
            fn () => $this->process($id),
        ]);
        $this->assertSame(1, array_sum(array_column($results, 'posts')));
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(1, ProductStockItem::whereNotNull('supplier_purchase_id')->count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $product->fresh()->stock);
    }

    #[Group('postgres-release')]
    public function test_nastele_workers_dispatch_one_non_idempotent_purchase_with_fractional_cost(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $product->update(['price_amount' => 50000]);
        $supplier->forceFill(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001', 'balance_micros' => 300_000_000_000])->save();
        $sku->update(['external_id' => '43', 'currency' => 'VND', 'unit_price_micros' => 30_000_000_000]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $id = SupplierFulfillment::where('order_id', $order->id)->sole()->id;
        $process = function () use ($id): array {
            Http::fake(function () {
                usleep(150000);

                return Http::response(['success' => true, 'data' => [
                    'orderId' => 2244, 'totalAmount' => 30000,
                    'items' => [['quantity' => 1, 'unitPrice' => 30000, 'accounts' => ['synthetic-race|password']]],
                ]], 201);
            });
            app(SupplierFulfillmentService::class)->process($id);

            return ['posts' => Http::recorded(fn ($request) => $request->method() === 'POST')->count()];
        };
        $results = $this->race([$process, $process, $process]);
        $this->assertSame(1, array_sum(array_column($results, 'posts')));
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(1, ProductStockItem::whereNotNull('supplier_purchase_id')->count());
        $this->assertSame(270_000_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(19501, SupplierMoney::costIdr(
            SupplierPurchase::sole()->actual_cost_micros, SupplierPurchase::sole()->exchange_rate_idr,
        ));
    }

    #[Group('postgres-release')]
    public function test_nastele_competing_checkouts_cannot_overreserve_one_vnd_wallet_unit(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $product->update(['price_amount' => 50000]);
        $supplier->forceFill(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001', 'balance_micros' => 30_000_000_000])->save();
        $sku->update(['external_id' => '43', 'currency' => 'VND', 'unit_price_micros' => 30_000_000_000]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $second = $this->cart($product->id, 790002);
        $results = $this->race([
            fn () => $this->checkoutOutcome($cart->id),
            fn () => $this->checkoutOutcome($second->id),
        ]);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['reserved', 'unavailable'], $outcomes);
        $this->assertSame(30_000_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(1, Order::count());
    }

    #[Group('postgres-release')]
    public function test_nastele_rate_correction_racing_checkouts_never_overreserves_or_deadlocks(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $product->update(['price_amount' => 50000]);
        // The broken production value: the rupiah price of one USDT kept as the rupiah price of one VND.
        // The wallet funds exactly two 69.000 VND units at the committed 0.692308, and none at 18000.
        $supplier->forceFill([
            'provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '18000',
            'balance_micros' => 138_000_000_000, 'reserved_balance_micros' => 0,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ])->save();
        $sku->update(['external_id' => '43', 'currency' => 'VND', 'unit_price_micros' => 69_000_000_000, 'stock' => 5]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(0, $product->fresh()->supplier_available_stock);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);

        $carts = [$cart, $this->cart($product->id, 790002), $this->cart($product->id, 790003)];
        $checkout = function (int $cartId): array {
            try {
                $this->checkout(Cart::findOrFail($cartId));

                return ['outcome' => 'reserved'];
            } catch (InsufficientStock) {
                return ['outcome' => 'unavailable'];
            } catch (Throwable $error) {
                $state = $error instanceof QueryException ? (string) ($error->errorInfo[0] ?? '') : '';

                return ['outcome' => 'error', 'error' => $state === '' ? $error::class : $error::class.' '.$state];
            }
        };
        $results = $this->race(array_merge(
            [fn (): array => ['outcome' => app(SupplierManager::class)->correctRate($supplier->fresh(), '0.692308', null, null) ? 'corrected' : 'unchanged']],
            array_map(fn (Cart $raceCart): callable => fn (): array => $checkout($raceCart->id), $carts),
        ));
        $outcomes = array_column($results, 'outcome');
        $reserved = count(array_filter($outcomes, fn (string $outcome): bool => $outcome === 'reserved'));

        // No worker may die on a lock-order deadlock or a lock timeout, and every checkout that loses
        // must lose with the documented unavailable-stock error instead of a half-committed reservation.
        $this->assertSame([], array_values(array_column($results, 'error')));
        $this->assertNotContains('unchanged', $outcomes);
        $this->assertContains('corrected', $outcomes);
        $this->assertLessThanOrEqual(2, $reserved);
        $fulfillments = SupplierFulfillment::query()->orderBy('id')->get();
        $this->assertSame($reserved, $fulfillments->count());
        $this->assertSame($reserved, Order::count());
        $this->assertSame(0, SupplierPurchase::count());
        $committedCost = 0;
        foreach ($fulfillments as $fulfillment) {
            $candidate = $fulfillment->sources_snapshot[0];
            $this->assertSame('0.692308', $candidate['exchange_rate_idr']);
            $this->assertSame(1, $fulfillment->reserved_quantity);
            $this->assertSame($candidate['expected_cost_micros'], $fulfillment->reserved_cost_micros);
            $this->assertSame(47770, SupplierMoney::costIdr($candidate['expected_cost_micros'], $candidate['exchange_rate_idr']));
            $this->assertSame('reserved', $fulfillment->state);
            $this->assertNull($fulfillment->current_purchase_id);
            $committedCost += $candidate['expected_cost_micros'];
        }
        $this->assertSame(69_000_000_000 * $reserved, $committedCost);
        $this->assertSame($committedCost, (int) $supplier->fresh()->reserved_balance_micros);
        $this->assertLessThanOrEqual((int) $supplier->fresh()->balance_micros, (int) $supplier->fresh()->reserved_balance_micros);
        $this->assertSame($reserved, (int) $sku->fresh()->reserved_quantity);
        foreach ($carts as $raceCart) {
            $orders = Order::query()->where('cart_id', $raceCart->id)->count();
            $this->assertLessThanOrEqual(1, $orders);
            $this->assertSame($orders === 1, $raceCart->fresh()->status === CartStatus::CheckedOut);
        }

        $this->assertSame('0.692308', $supplier->fresh()->exchange_rate_idr);
        // A rate does not invalidate the provider observation, so capacity is recomputed from it.
        $this->assertTrue((bool) ($supplier->fresh()->sync_expires_at?->isFuture()));
        $audit = AuditLog::query()->where('action', 'supplier.rate_corrected')->sole();
        $this->assertTrue(SupplierExchangeRate::equals('18000', $audit->old_values['exchange_rate_idr'] ?? null));
        $this->assertSame('0.692308', $audit->new_values['exchange_rate_idr'] ?? null);
        $this->assertSame('suppliers:rate-audit', $audit->new_values['command'] ?? null);

        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->sole();
        $unheldSku = clone $sku->fresh();
        $unheldSku->reserved_quantity = 0;
        $unheldSupplier = clone $supplier->fresh();
        $unheldSupplier->reserved_balance_micros = 0;
        $capacityAtCommittedRate = app(SupplierPricing::class)
            ->capacity($product->fresh(), $unheldSupplier, $unheldSku, $policy);
        $this->assertSame(2, $capacityAtCommittedRate);
        $this->assertSame($capacityAtCommittedRate - $reserved, (int) $product->fresh()->supplier_available_stock);
        $this->assertSame($capacityAtCommittedRate > $reserved, (bool) ($product->fresh()->supplier_available_until?->isFuture()));
    }

    #[Group('postgres-release')]
    public function test_killed_queue_worker_retains_dispatch_episode_and_recovers_one_evidence_decision(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture();
        $supplier->update(['provider' => 'toolorax', 'currency' => 'USD']);
        $sku->update(['external_id' => '4']);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        config()->set('queue.connections.supplier.driver', 'database');
        app()->forgetInstance('queue');
        Queue::clearResolvedInstance('queue');
        app(SupplierFulfillmentService::class)->recover();
        DB::disconnect();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            DB::purge();
            DB::reconnect();
            Http::fake(function ($request) {
                if ($request->method() !== 'POST') {
                    throw new \RuntimeException('Expected original financial POST.');
                }
                posix_kill(getmypid(), SIGKILL);
                exit(90);
            });
            Artisan::call('queue:work', ['connection' => 'supplier', '--queue' => 'suppliers', '--once' => true, '--tries' => 1, '--timeout' => 120]);
            exit(91);
        }
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        DB::reconnect();
        $purchase = SupplierPurchase::sole();
        $episode = DB::table('supplier_purchase_dispatches')->where('supplier_purchase_id', $purchase->id)->sole();
        $this->assertSame('started', $episode->outcome);
        $this->assertSame($purchase->claim_token, $episode->claim_token);
        $this->assertNull($episode->finished_at);
        $purchase->update(['lease_expires_at' => now()->subSecond()]);
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());

            return Http::response(['ok' => true, 'count' => 1, 'orders' => [[
                'order_code' => 'unrelated-old-order', 'product_id' => 8, 'quantity' => 1,
                'total' => '1.000', 'status' => 'fulfilled', 'created_at' => now()->subHour()->format('Y-m-d H:i:s'),
            ]]]);
        });
        $this->assertSame(['processed' => 1], app(SupplierFulfillmentService::class)->recover());
        Http::assertNothingSent();
        Artisan::call('queue:work', ['connection' => 'supplier', '--queue' => 'suppliers', '--once' => true, '--tries' => 1, '--timeout' => 120]);
        $this->assertSame(['processed' => 0], app(SupplierFulfillmentService::class)->recover());
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        Http::assertSentCount(1);
        $this->assertSame('ambiguous', $purchase->fresh()->state);
        $this->assertNotNull($purchase->fresh()->health_review_episode);
        $this->assertSame('unknown', DB::table('supplier_purchase_dispatches')->where('id', $episode->id)->value('outcome'));
        $this->assertSame('history_timezone_unverified', $purchase->historyReconciliations()->sole()->error_code);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(1, SupplierPurchase::count());
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $history = app(SupplierOrderHistoryReconciliationService::class)
            ->reconcile($fulfillment->id, $actor, (string) config('app.timezone'));
        $this->assertSame('no_order', $history->outcome);
        for ($replay = 0; $replay < 2; $replay++) {
            app(SupplierFulfillmentService::class)->confirmNoDebit(
                $fulfillment->id, $actor, '', 'Provider timezone attested and complete history verified.',
                evidenceSource: 'provider_history', historyReconciliationId: $history->id,
            );
        }
        $this->assertSame('rejected', $purchase->fresh()->state);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $purchase->evidence()->where('kind', 'no_debit')->count());
    }

    public function test_checkout_wallet_race_commits_only_one_customer_when_balance_covers_one_unit(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture();
        $supplier->forceFill(['balance_micros' => 1_000_000])->save();
        app(SupplierAvailability::class)->refreshProducts([$cart->cartItems()->sole()->product_id]);
        $second = $this->cart($cart->cartItems()->sole()->product_id, 790002);
        $results = $this->race([
            fn () => $this->checkoutOutcome($cart->id),
            fn () => $this->checkoutOutcome($second->id),
        ]);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['reserved', 'unavailable'], $outcomes);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(1, Order::count());
    }

    public function test_refund_and_purchase_race_never_credits_or_buys_after_the_other_terminal_decision(): void
    {
        [, $supplier, , $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $id = SupplierFulfillment::where('order_id', $order->id)->sole()->id;
        $results = $this->race([
            fn () => $this->process($id),
            function () use ($order): array {
                try {
                    app(OrderLifecycle::class)->applyPaymentRefunded($order->payments()->sole());

                    return ['outcome' => 'refunded'];
                } catch (InvalidOrderTransition) {
                    return ['outcome' => 'held'];
                }
            },
        ]);
        $posts = array_sum(array_column($results, 'posts'));
        $this->assertLessThanOrEqual(1, $posts);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        if ($order->fresh()->payment_status === PaymentStatus::Refunded) {
            $this->assertSame(0, ProductStockItem::whereNull('retired_at')->count());
        } else {
            $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
            $this->assertSame(1, $posts);
            $this->assertSame(1, ProductStockItem::count());
        }
    }

    #[Group('postgres-release')]
    public function test_duplicate_no_debit_confirmations_release_one_hold_and_record_one_evidence_row(): void
    {
        [, $supplier, $sku, $cart] = $this->fixture();
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::query()->where('order_id', $order->id)->sole();
        $purchase = SupplierPurchase::query()->create([
            'supplier_fulfillment_id' => $fulfillment->id,
            'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $sku->id,
            'reference' => (string) Str::uuid(),
            'quantity' => 1,
            'expected_cost_micros' => 1_000_000,
            'exchange_rate_idr' => 1000,
            'state' => 'ambiguous',
            'dispatched_at' => now()->subMinute(),
        ]);
        $fulfillment->forceFill(['state' => 'review', 'current_purchase_id' => $purchase->id])->save();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $reference = 'provider-case-concurrent-no-debit';
        $note = 'Provider confirmed there is no order and no debit.';

        $results = $this->race([
            fn (): array => ['state' => app(SupplierFulfillmentService::class)->confirmNoDebit($fulfillment->id, $actor, $reference, $note)->state],
            fn (): array => ['state' => app(SupplierFulfillmentService::class)->confirmNoDebit($fulfillment->id, $actor, $reference, $note)->state],
        ]);

        $this->assertSame([['state' => 'rejected'], ['state' => 'rejected']], $results);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(1, SupplierPurchaseEvidence::query()->where('supplier_purchase_id', $purchase->id)->where('kind', 'no_debit')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier_purchase.no_debit_confirmed')->count());
        $this->assertSame(1, SupplierPurchase::query()->count());
    }

    #[Group('postgres-release')]
    public function test_csx_history_candidate_confirmation_race_and_replay_commit_one_delivery_and_receipt_obligation(): void
    {
        [$product, $supplier, $sku, $order, $fulfillment, $purchase] = $this->csxReviewFixture();
        $actors = User::factory()->count(2)->create(['role' => UserRole::Owner]);
        $orderId = 'synthetic-csx-history-race';
        $version = $purchase->updated_at->format('Y-m-d H:i:s');
        Http::fake(fn () => Http::response([
            'success' => true, 'page' => 1, 'limit' => 100, 'total_orders' => 1, 'total_pages' => 1,
            'orders' => [[
                'order_id' => $orderId, 'service_id' => $sku->external_id, 'quantity' => 1,
                'total_cost' => '1.00', 'status' => 'completed', 'created_at' => now()->format('Y-m-d H:i:s'),
            ]],
        ]));
        $history = app(SupplierOrderHistoryReconciliationService::class)->reconcile(
            $fulfillment->id, $actors[0], '',
            expectedPurchaseId: $purchase->id, expectedPurchaseVersion: $version,
        );
        $this->assertContains($orderId, $history->candidate_order_ids);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        Queue::assertNotPushed(SendTelegramReceipt::class);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($orderId, $sku) {
            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/order/'.$orderId, $request->url());
            usleep(200000);

            return Http::response([
                'success' => true, 'status' => 'completed', 'order_id' => $orderId,
                'service_id' => $sku->external_id, 'quantity' => 1, 'total_cost' => '1.00',
                'products' => ['synthetic-csx-race-owned-credential'],
            ]);
        });
        $confirm = function (User $actor) use ($fulfillment, $history, $orderId, $purchase, $version): array {
            try {
                $confirmed = app(SupplierOrderHistoryReconciliationService::class)->confirmCandidate(
                    $fulfillment->id, $actor, $history->id, $orderId,
                    'Synthetic provider history and exact order confirm this original purchase.',
                    expectedPurchaseId: $purchase->id, expectedPurchaseVersion: $version,
                );
                $outcome = 'confirmed';
                $this->assertSame($purchase->id, $confirmed->id);
                $this->assertSame('delivered', $confirmed->state);
            } catch (InvalidOrderTransition) {
                $outcome = 'rejected';
            }

            return [
                'outcome' => $outcome,
                'reads' => Http::recorded()->count(),
                'receipts' => Queue::pushed(SendTelegramReceipt::class)->count(),
            ];
        };
        $results = $this->race([
            fn (): array => $confirm($actors[0]),
            fn (): array => $confirm($actors[1]),
        ]);
        $this->assertContains('confirmed', array_column($results, 'outcome'));
        $this->assertSame(1, array_sum(array_column($results, 'reads')));
        $this->assertSame(1, array_sum(array_column($results, 'receipts')));
        for ($replay = 0; $replay < 2; $replay++) {
            $this->assertSame(['outcome' => 'confirmed', 'reads' => 0, 'receipts' => 0], $confirm($actors[$replay]));
        }
        Http::assertNothingSent();
        Queue::assertNotPushed(SendTelegramReceipt::class);

        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame($orderId, $purchase->fresh()->supplier_order_id);
        $this->assertSame(1_000_000, $purchase->fresh()->actual_cost_micros);
        $this->assertNotNull($purchase->fresh()->committed_at);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, SupplierPurchaseEvidence::where('supplier_purchase_id', $purchase->id)->where('kind', 'debit_confirmed')->count());
        $this->assertSame(1, AuditLog::where('action', 'supplier_purchase.debit_confirmed')->count());
        $allocation = ProductStockItem::where('supplier_purchase_id', $purchase->id)->sole();
        $this->assertSame($fulfillment->order_item_id, $allocation->order_item_id);
        $this->assertSame('synthetic-csx-race-owned-credential', $allocation->secret);
        $this->assertSame(1, ProductStockItem::count());
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);

        // The completed Order is the durable receipt handoff; repeated materialization owns one group.
        $receipts = app(TelegramOutboundEffectService::class);
        $first = $receipts->recordReceipt($order->fresh());
        $this->assertNotNull($first);
        $this->assertSame($first->id, $receipts->recordReceipt($order->fresh())?->id);
        $this->assertSame(1, $order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->distinct()->count('effect_group_key'));
        Http::assertNothingSent();
    }

    #[Group('postgres-release')]
    public function test_csx_history_candidate_claimed_by_another_purchase_after_scan_is_rejected_without_releasing_holds(): void
    {
        [$product, $supplier, $sku, , $fulfillment, $purchase] = $this->csxReviewFixture();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $orderId = 'synthetic-csx-colliding-history-order';
        $version = $purchase->updated_at->format('Y-m-d H:i:s');
        Http::fake(function ($request) use ($orderId, $sku) {
            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/orders', parse_url($request->url(), PHP_URL_PATH));

            return Http::response([
                'success' => true, 'page' => 1, 'limit' => 100, 'total_orders' => 1, 'total_pages' => 1,
                'orders' => [[
                    'order_id' => $orderId, 'service_id' => $sku->external_id, 'quantity' => 1,
                    'total_cost' => '1.00', 'status' => 'completed', 'created_at' => now()->format('Y-m-d H:i:s'),
                ]],
            ]);
        });
        $history = app(SupplierOrderHistoryReconciliationService::class)->reconcile(
            $fulfillment->id, $actor, '',
            expectedPurchaseId: $purchase->id, expectedPurchaseVersion: $version,
        );
        $this->assertContains($orderId, $history->candidate_order_ids);

        $otherOrder = $this->checkout($this->cart($product->id, 790002));
        app(OrderLifecycle::class)->applyManualCompletion($otherOrder);
        $otherFulfillment = SupplierFulfillment::where('order_id', $otherOrder->id)->sole();
        $otherPurchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $otherFulfillment->id,
            'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $sku->id,
            'reference' => (string) Str::uuid(),
            'quantity' => 1,
            'expected_cost_micros' => 1_000_000,
            'exchange_rate_idr' => 1000,
            'state' => 'awaiting_delivery',
            'supplier_order_id' => $orderId,
            'dispatched_at' => now()->subMinute(),
        ]);
        $otherFulfillment->forceFill(['state' => 'awaiting_delivery', 'current_purchase_id' => $otherPurchase->id])->save();

        try {
            app(SupplierOrderHistoryReconciliationService::class)->confirmCandidate(
                $fulfillment->id, $actor, $history->id, $orderId,
                'Synthetic candidate is now owned by another purchase.',
                expectedPurchaseId: $purchase->id, expectedPurchaseVersion: $version,
            );
            $this->fail('A candidate claimed by another purchase must not be confirmed.');
        } catch (InvalidOrderTransition) {
            $this->assertSame('ambiguous', $purchase->fresh()->state);
        }

        Http::assertSentCount(1);
        $this->assertNull($purchase->fresh()->supplier_order_id);
        $this->assertNull($purchase->fresh()->committed_at);
        $this->assertNull($purchase->fresh()->actual_cost_micros);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame($orderId, $otherPurchase->fresh()->supplier_order_id);
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(2_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(2, $sku->fresh()->reserved_quantity);
        $this->assertSame(2, SupplierPurchase::count());
        $this->assertSame(0, SupplierPurchaseEvidence::count());
        $this->assertSame(0, AuditLog::where('action', 'supplier_purchase.debit_confirmed')->count());
        $this->assertSame(0, ProductStockItem::count());
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_sync_racing_checkout_keeps_wallet_and_sku_commitments(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $this->race([
            fn () => $this->checkoutOutcome($cart->id),
            function () use ($supplier): array {
                Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => '10', 'currency' => 'USDT'] : ['products' => [['id' => 'fixture-sku', 'name' => 'Private', 'price' => '1', 'stock' => 10]], 'currency' => 'USDT']));

                return ['applied' => app(SupplierCatalogSync::class)->sync($supplier)];
            },
        ]);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, SupplierFulfillment::count());
    }

    public function test_disjoint_multi_product_carts_lock_shared_wallets_in_global_order(): void
    {
        [$first, $supplierA, $skuA, $cartA] = $this->fixture();
        $supplierA->forceFill(['balance_micros' => 1_000_000])->save();
        $supplierB = $supplierA->replicate();
        $supplierB->api_key = 'second-wallet-fixture';
        $supplierB->save();
        $skuB = $skuA->replicate();
        $skuB->supplier_id = $supplierB->id;
        $skuB->save();
        $products = Product::factory()->count(3)->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        foreach ($products as $index => $product) {
            SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
            ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $index === 2 ? $skuA->id : $skuB->id, 'is_active' => true, 'priority' => 0]);
        }
        CartItem::factory()->create(['cart_id' => $cartA->id, 'product_id' => $products[0]->id, 'quantity' => 1]);
        $cartB = $this->cart($products[1]->id, 790002);
        CartItem::factory()->create(['cart_id' => $cartB->id, 'product_id' => $products[2]->id, 'quantity' => 1]);
        app(SupplierAvailability::class)->refreshProducts(array_merge([$first->id], $products->modelKeys()));
        $results = $this->race([fn () => $this->checkoutOutcome($cartA->id), fn () => $this->checkoutOutcome($cartB->id)]);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['reserved', 'unavailable'], $outcomes);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, SupplierFulfillment::count());
        $this->assertSame(1_000_000, $supplierA->fresh()->reserved_balance_micros);
        $this->assertSame(1_000_000, $supplierB->fresh()->reserved_balance_micros);
        $this->assertSame(1, $skuA->fresh()->reserved_quantity);
        $this->assertSame(1, $skuB->fresh()->reserved_quantity);
    }

    #[Group('postgres-release')]
    public function test_checkout_product_lock_serializes_detach_and_protects_retained_snapshot(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $source = ProductSupplierSource::where('product_id', $product->id)->sole();
        $input = ['source_id' => $source->id, 'version' => $source->version, 'supplier_version' => $supplier->fresh()->version];
        $results = $this->serializedProductRace($product->id,
            fn (): array => $this->checkoutOutcome($cart->id),
            function () use ($product, $sku, $input, $actor): array {
                try {
                    app(ProductSupplierManager::class)->detach($product, $sku, $input, $actor, null);

                    return ['outcome' => 'detached'];
                } catch (ValidationException $exception) {
                    return ['outcome' => 'stale', 'errors' => array_keys($exception->errors())];
                }
            });
        $this->assertSame([['outcome' => 'reserved'], ['outcome' => 'stale', 'errors' => ['version']]], $results);
        $this->assertModelExists($source);
        $fulfillment = SupplierFulfillment::sole();
        $this->assertSame($sku->id, $fulfillment->reserved_catalog_item_id);
        $this->assertSame($source->id, $fulfillment->sources_snapshot[0]['source_id']);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, AuditLog::where('action', 'supplier.source_detached')->count());
        // Checkout increments the wallet version; even a reloaded form cannot bypass the retained-history guard.
        $input['supplier_version'] = $supplier->fresh()->version;
        try {
            app(ProductSupplierManager::class)->detach($product, $sku, $input, $actor, null);
            $this->fail('A refreshed form must still protect the checkout snapshot.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertModelExists($source);
        $this->assertSame(0, AuditLog::where('action', 'supplier.source_detached')->count());
    }

    #[Group('postgres-release')]
    public function test_detach_product_lock_serializes_checkout_before_source_snapshot_is_created(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $source = ProductSupplierSource::where('product_id', $product->id)->sole();
        $input = ['source_id' => $source->id, 'version' => $source->version, 'supplier_version' => $supplier->fresh()->version];
        $results = $this->serializedProductRace($product->id,
            function () use ($product, $sku, $input, $actor): array {
                app(ProductSupplierManager::class)->detach($product, $sku, $input, $actor, null);

                return ['outcome' => 'detached'];
            },
            fn (): array => $this->checkoutOutcome($cart->id));
        $this->assertSame([['outcome' => 'detached'], ['outcome' => 'unavailable']], $results);
        $this->assertModelMissing($source);
        $this->assertSame(0, SupplierFulfillment::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->supplier_available_stock);
        $this->assertSame(1, AuditLog::where('action', 'supplier.source_detached')->count());
    }

    /** Hold Product until PostgreSQL confirms the competing real mutation is waiting on its lock. */
    private function serializedProductRace(int $productId, callable $holder, callable $contender): array
    {
        $directory = storage_path('framework/testing/supplier-detach-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        try {
            return $this->race([
                function () use ($productId, $holder, $directory): array {
                    return DB::transaction(function () use ($productId, $holder, $directory): array {
                        Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                        touch($directory.'/locked');
                        $deadline = microtime(true) + 5;
                        do {
                            if (is_file($directory.'/contender')) {
                                $pid = (int) file_get_contents($directory.'/contender');
                                $blocked = DB::selectOne('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked', [$pid]);
                                if (in_array($blocked->blocked, [true, 1, '1', 't'], true)) {
                                    return $holder();
                                }
                            }
                            usleep(1000);
                        } while (microtime(true) < $deadline);
                        throw new \RuntimeException('Competing supplier mutation did not wait on the Product lock.');
                    });
                },
                function () use ($contender, $directory): array {
                    $deadline = microtime(true) + 5;
                    while (! is_file($directory.'/locked') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (! is_file($directory.'/locked')) {
                        throw new \RuntimeException('Product lock was not acquired.');
                    }
                    file_put_contents($directory.'/contender', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);

                    return $contender();
                },
            ]);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function process(int $id): array
    {
        Http::fake(function () {
            usleep(200000);

            return Http::response(['status' => 'delivered', 'orderId' => 'race-order', 'quantity' => 1, 'total_cost' => '1', 'deliveredKeys' => ['race-owned-key']]);
        });
        app(SupplierFulfillmentService::class)->process($id);

        return ['posts' => Http::recorded(fn ($request) => $request->method() === 'POST')->count()];
    }

    private function checkoutOutcome(int $cartId): array
    {
        try {
            $this->checkout(Cart::findOrFail($cartId));

            return ['outcome' => 'reserved'];
        } catch (InsufficientStock) {
            return ['outcome' => 'unavailable'];
        }
    }

    private function csxReviewFixture(): array
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        $supplier->update(['provider' => 'csxstore', 'currency' => 'USD']);
        $order = $this->checkout($cart);
        app(OrderLifecycle::class)->applyManualCompletion($order);
        $fulfillment = SupplierFulfillment::where('order_id', $order->id)->sole();
        $purchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id,
            'supplier_id' => $supplier->id,
            'supplier_catalog_item_id' => $sku->id,
            'reference' => (string) Str::uuid(),
            'quantity' => 1,
            'expected_cost_micros' => 1_000_000,
            'exchange_rate_idr' => 1000,
            'state' => 'ambiguous',
            'dispatched_at' => now()->subMinute(),
        ]);
        $fulfillment->forceFill(['state' => 'review', 'current_purchase_id' => $purchase->id])->save();

        return [$product, $supplier, $sku, $order, $fulfillment, $purchase];
    }

    private function fixture(): array
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        $supplier = Supplier::forceCreate(['name' => 'Private', 'provider' => 'prodseller', 'api_key' => 'concurrency-fixture', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 10_000_000, 'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $sku = SupplierCatalogItem::create(['supplier_id' => $supplier->id, 'external_id' => 'fixture-sku', 'name' => 'Private', 'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => []]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);

        return [$product, $supplier, $sku, $this->cart($product->id, 790001)];
    }

    private function cart(int $productId, int $telegramId): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $productId, 'quantity' => 1]);

        return $cart;
    }

    private function checkout(Cart $cart): Order
    {
        return app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    }

    private function race(array $callbacks): array
    {
        $directory = storage_path('framework/testing/supplier-race-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $children = [];
        DB::disconnect();
        try {
            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork supplier worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout TO '8s'");
                        DB::statement("SET statement_timeout TO '12s'");
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/release') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        $result = ['ok' => true, 'value' => $callback()];
                    } catch (Throwable $error) {
                        $result = ['ok' => false, 'error' => $error::class];
                    }
                    file_put_contents($directory.'/'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                    exit($result['ok'] ? 0 : 1);
                }
                $children[$index] = $pid;
            }
            touch($directory.'/release');
            $remaining = $children;
            $deadline = microtime(true) + 25;
            while ($remaining !== [] && microtime(true) < $deadline) {
                foreach ($remaining as $index => $pid) {
                    if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                        unset($remaining[$index]);
                    }
                }
                usleep(10000);
            }
            foreach ($remaining as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            $this->assertSame([], $remaining, 'Supplier concurrency workers exceeded the bounded deadline.');
            $results = [];
            foreach ($children as $index => $pid) {
                $result = json_decode(file_get_contents($directory.'/'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['ok'], $result['error'] ?? 'Worker failed');
                $results[] = $result['value'];
            }

            return $results;
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
