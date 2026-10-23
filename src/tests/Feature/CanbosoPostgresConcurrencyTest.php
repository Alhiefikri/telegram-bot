<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStock;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class CanbosoPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace;

    private bool $ownsDisposableDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || (string) env('RUN_CONCURRENCY_TESTS', '') !== '1'
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Canboso races require isolated PostgreSQL, RUN_CONCURRENCY_TESTS=1, pcntl and posix.');
        }
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        $this->ownsDisposableDatabase = true;
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Queue::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownsDisposableDatabase) {
                PostgresTestDatabase::migrateFresh();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_purchases_share_one_provider_rate_budget_across_processes(): void
    {
        [, $supplier, $sku] = $this->fixture();
        config(['cache.default' => 'database', 'cache.limiter' => 'database']);
        Cache::purge('database');
        Cache::store('database')->flush();
        $callbacks = [];
        foreach (range(1, 4) as $worker) {
            $callbacks[] = function () use ($worker, $supplier, $sku): array {
                Cache::purge('database');
                \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('database')));
                Http::fake(fn () => Http::response($this->purchasePayload()));
                $outcomes = ['delivered' => 0, 'rejected' => 0];
                foreach (range(1, 10) as $attempt) {
                    $result = app(SupplierClient::class)->purchase($supplier, $sku, 1, "canboso-budget-{$worker}-{$attempt}");
                    $this->assertContains($result->outcome, ['delivered', 'rejected']);
                    if ($result->outcome === 'rejected') {
                        $this->assertSame('rate_limited', $result->errorCode);
                    }
                    $outcomes[$result->outcome]++;
                }

                return $outcomes + $this->transportCounts();
            };
        }
        $results = $this->race($callbacks);
        $this->assertSame(30, array_sum(array_column($results, 'posts')));
        $this->assertSame(30, array_sum(array_column($results, 'delivered')));
        $this->assertSame(10, array_sum(array_column($results, 'rejected')));
        $this->assertSame(0, array_sum(array_column($results, 'gets')));
    }

    public function test_competing_workers_dispatch_one_canboso_purchase_and_allocate_one_owned_credential(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        [$order, $fulfillment] = $this->paid($cart);
        $results = $this->withBarriers(function (string $directory) use ($fulfillment): array {
            return $this->race([
                function () use ($fulfillment, $directory): array {
                    Http::fake(function ($request) use ($directory) {
                        $this->assertPurchaseRequest($request);
                        touch($directory.'/dispatch');
                        $this->awaitBarrier($directory.'/contender-finished');

                        return Http::response($this->purchasePayload());
                    });
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);

                    return $this->transportCounts();
                },
                function () use ($fulfillment, $directory): array {
                    $this->awaitBarrier($directory.'/dispatch');
                    $purchase = SupplierPurchase::sole();
                    $this->assertSame('sending', $purchase->state);
                    $this->assertNotNull($purchase->dispatched_at);
                    $this->assertTrue($purchase->lease_expires_at->isFuture());
                    Http::fake(fn () => Http::response($this->purchasePayload()));
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);
                    touch($directory.'/contender-finished');

                    return $this->transportCounts();
                },
            ]);
        });

        $this->assertSame([['posts' => 1, 'gets' => 0], ['posts' => 0, 'gets' => 0]], $results);
        $this->assertSame(1, DB::table('supplier_purchase_dispatches')->count());
        $this->assertDelivered($product, $supplier, $sku, $order, $fulfillment);
    }

    public function test_two_customers_on_disjoint_products_compete_for_a_wallet_that_covers_one_unit(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(balance: 1_000_000);
        $otherProduct = $this->mappedProduct($sku);
        $otherCart = $this->cart($otherProduct->id, 791002);
        app(SupplierAvailability::class)->refreshProducts([$otherProduct->id]);

        $results = $this->serializedCheckouts($cart, $otherCart);

        $this->assertSame(['reserved', 'unavailable'], array_column($results, 'outcome'));
        $this->assertReservedWinner($cart, $otherCart, $supplier, $sku, $results[0]['order_id']);
        $this->assertSame(1_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(10, $sku->fresh()->stock);
        $this->assertSame(0, $product->fresh()->supplier_available_stock);
        $this->assertSame(0, $otherProduct->fresh()->supplier_available_stock);
    }

    public function test_two_customers_compete_for_the_last_external_stock_unit_without_creating_local_inventory(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture(stock: 1);
        $otherCart = $this->cart($product->id, 791002);

        $results = $this->serializedCheckouts($cart, $otherCart);

        $this->assertSame(['reserved', 'unavailable'], array_column($results, 'outcome'));
        $this->assertReservedWinner($cart, $otherCart, $supplier, $sku, $results[0]['order_id']);
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(1, $sku->fresh()->stock);
        $this->assertSame(0, $product->fresh()->supplier_available_stock);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_concurrent_catalog_sync_uses_one_snapshot_and_preserves_unsettled_wallet_and_quantity_holds(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        [$order, $fulfillment] = $this->paid($cart);
        Http::fake(fn () => throw new RuntimeException('Synthetic lost Canboso response.'));
        app(SupplierFulfillmentService::class)->process($fulfillment->id);
        $purchase = SupplierPurchase::sole();
        $this->assertNull($purchase->committed_at);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $version = $supplier->fresh()->version;

        $results = $this->withBarriers(function (string $directory) use ($supplier): array {
            return $this->race([
                function () use ($supplier, $directory): array {
                    Http::fake(function ($request) use ($directory) {
                        $this->assertSame('GET', $request->method());
                        $this->assertSame('synthetic-canboso-buyer-key', $request['key']);
                        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/balance')) {
                            touch($directory.'/snapshot');
                            $this->awaitBarrier($directory.'/contender-finished');

                            return Http::response(['success' => true, 'walletCurrency' => 'USD', 'balance' => '9.00']);
                        }

                        return Http::response($this->catalogPayload(stock: 9));
                    });
                    $applied = app(SupplierCatalogSync::class)->sync($supplier);

                    return ['applied' => $applied] + $this->transportCounts();
                },
                function () use ($supplier, $directory): array {
                    $this->awaitBarrier($directory.'/snapshot');
                    $this->assertNotNull($supplier->fresh()->sync_token);
                    $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
                    Http::fake(fn () => Http::response($this->catalogPayload()));
                    $applied = app(SupplierCatalogSync::class)->sync($supplier);
                    touch($directory.'/contender-finished');

                    return ['applied' => $applied] + $this->transportCounts();
                },
            ]);
        });

        $this->assertSame([
            ['applied' => true, 'posts' => 0, 'gets' => 2],
            ['applied' => false, 'posts' => 0, 'gets' => 0],
        ], $results);
        $this->assertSame($version + 1, $supplier->fresh()->version);
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertNull($supplier->fresh()->sync_token);
        $this->assertNull($supplier->fresh()->sync_lease_expires_at);
        $this->assertSame(9, $sku->fresh()->stock);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(8, $product->fresh()->supplier_available_stock);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::count());
        $this->assertNull($purchase->fresh()->committed_at);
        $this->assertSame($order->id, $fulfillment->fresh()->order_id);
        $this->assertSame($order->orderItems()->sole()->id, $fulfillment->fresh()->order_item_id);
        $this->assertSame(1, SupplierPurchase::count());
    }

    public function test_old_snapshot_cannot_publish_after_a_real_credential_and_version_rotation(): void
    {
        [$product, $supplier, $sku] = $this->fixture();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $version = $supplier->version;
        $skuSyncedAt = $sku->synced_at->format('Y-m-d H:i:s');

        $results = $this->withBarriers(function (string $directory) use ($supplier, $actor): array {
            return $this->race([
                function () use ($supplier, $directory): array {
                    Http::fake(function ($request) use ($directory) {
                        $this->assertSame('GET', $request->method());
                        $this->assertSame('synthetic-canboso-buyer-key', $request['key']);
                        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/balance')) {
                            touch($directory.'/snapshot');
                            $this->awaitBarrier($directory.'/rotated');

                            return Http::response(['success' => true, 'walletCurrency' => 'USD', 'balance' => '99.00']);
                        }

                        return Http::response($this->catalogPayload(stock: 99, name: 'Stale catalog must not publish'));
                    });
                    $applied = app(SupplierCatalogSync::class)->sync($supplier);

                    return ['applied' => $applied] + $this->transportCounts();
                },
                function () use ($supplier, $actor, $directory): array {
                    $this->awaitBarrier($directory.'/snapshot');
                    $current = $supplier->fresh();
                    $saved = app(SupplierManager::class)->save($current, [
                        'name' => $current->name, 'provider' => 'canboso',
                        'api_key' => 'synthetic-canboso-rotated-key', 'currency' => 'USD',
                        'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
                        'is_active' => true, 'low_balance_threshold_micros' => 0,
                        'version' => $current->version,
                    ], $actor, null);
                    touch($directory.'/rotated');

                    return ['version' => $saved->version];
                },
            ]);
        });

        $this->assertSame(['applied' => false, 'posts' => 0, 'gets' => 2], $results[0]);
        $this->assertSame(['version' => $version + 1], $results[1]);
        $this->assertSame($version + 1, $supplier->fresh()->version);
        $this->assertSame('synthetic-canboso-rotated-key', $supplier->fresh()->api_key);
        $this->assertStringNotContainsString('synthetic-canboso-rotated-key', $supplier->fresh()->getRawOriginal('api_key'));
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertNull($supplier->fresh()->synced_at);
        $this->assertNull($supplier->fresh()->sync_expires_at);
        $this->assertNull($supplier->fresh()->sync_token);
        $this->assertSame('Synthetic Canboso account', $sku->fresh()->name);
        $this->assertSame(10, $sku->fresh()->stock);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame($skuSyncedAt, $sku->fresh()->synced_at->format('Y-m-d H:i:s'));
        $this->assertNull($product->fresh()->supplier_available_until);
        $this->assertSame(0, Order::count());
        $this->assertSame(0, SupplierPurchase::count());
        $this->assertSame(0, ProductStockItem::count());
    }

    public function test_expired_dispatch_replays_the_same_reference_once_and_discards_late_competing_finalization(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        [$order, $fulfillment] = $this->paid($cart);

        $results = $this->withBarriers(function (string $directory) use ($fulfillment): array {
            return $this->race([
                function () use ($fulfillment, $directory): array {
                    $reference = null;
                    Http::fake(function ($request) use ($directory, &$reference) {
                        $this->assertPurchaseRequest($request);
                        $reference = $request->header('Idempotency-Key')[0];
                        touch($directory.'/original-dispatch');
                        $this->awaitBarrier($directory.'/replay-finalized');

                        return Http::response($this->purchasePayload(password: 'synthetic-stale-password'));
                    });
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);

                    return ['reference' => $reference] + $this->transportCounts();
                },
                function () use ($fulfillment, $directory): array {
                    $this->awaitBarrier($directory.'/original-dispatch');
                    $purchase = SupplierPurchase::sole();
                    $originalReference = $purchase->reference;
                    $purchase->update(['lease_expires_at' => now()->subSecond()]);
                    $reference = null;
                    Http::fake(function ($request) use (&$reference, $originalReference) {
                        $this->assertPurchaseRequest($request);
                        $reference = $request->header('Idempotency-Key')[0];
                        $this->assertSame($originalReference, $reference);

                        return Http::response($this->purchasePayload());
                    });
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);
                    $this->assertSame('delivered', SupplierPurchase::sole()->state);
                    touch($directory.'/replay-finalized');

                    return ['reference' => $reference] + $this->transportCounts();
                },
            ]);
        });

        $purchase = SupplierPurchase::sole();
        $this->assertSame($purchase->reference, $results[0]['reference']);
        $this->assertSame($purchase->reference, $results[1]['reference']);
        $this->assertSame(2, array_sum(array_column($results, 'posts')));
        $this->assertSame(0, array_sum(array_column($results, 'gets')));
        $this->assertSame(1, $purchase->replay_count);
        $this->assertSame(['unknown', 'delivered'], DB::table('supplier_purchase_dispatches')->orderBy('id')->pluck('outcome')->all());
        $this->assertDelivered($product, $supplier, $sku, $order, $fulfillment);
        $this->assertStringNotContainsString('synthetic-stale-password', ProductStockItem::sole()->secret);
    }

    public function test_two_lost_dispatch_leases_stop_at_one_same_reference_replay_and_keep_the_customer_hold(): void
    {
        [$product, $supplier, $sku, $cart] = $this->fixture();
        [$order, $fulfillment] = $this->paid($cart);

        $results = $this->withBarriers(function (string $directory) use ($fulfillment): array {
            return $this->race([
                function () use ($fulfillment, $directory): array {
                    $reference = null;
                    Http::fake(function ($request) use ($directory, &$reference) {
                        $this->assertPurchaseRequest($request);
                        $reference = $request->header('Idempotency-Key')[0];
                        touch($directory.'/original-dispatch');
                        $this->awaitBarrier($directory.'/bounded-review');

                        return Http::response($this->purchasePayload());
                    });
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);

                    return ['reference' => $reference] + $this->transportCounts();
                },
                function () use ($fulfillment, $directory): array {
                    $this->awaitBarrier($directory.'/original-dispatch');
                    SupplierPurchase::sole()->update(['lease_expires_at' => now()->subSecond()]);
                    $reference = null;
                    Http::fake(function ($request) use ($directory, &$reference) {
                        $this->assertPurchaseRequest($request);
                        $reference = $request->header('Idempotency-Key')[0];
                        touch($directory.'/replay-dispatch');
                        $this->awaitBarrier($directory.'/bounded-review');

                        return Http::response($this->purchasePayload());
                    });
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);

                    return ['reference' => $reference] + $this->transportCounts();
                },
                function () use ($fulfillment, $directory): array {
                    $this->awaitBarrier($directory.'/replay-dispatch');
                    $purchase = SupplierPurchase::sole();
                    $this->assertSame(1, $purchase->replay_count);
                    $purchase->update(['lease_expires_at' => now()->subSecond()]);
                    Http::fake(fn () => Http::response($this->purchasePayload()));
                    app(SupplierFulfillmentService::class)->process($fulfillment->id);
                    $this->assertSame('ambiguous', $purchase->fresh()->state);
                    $this->assertSame('review', $fulfillment->fresh()->state);
                    touch($directory.'/bounded-review');

                    return $this->transportCounts();
                },
            ]);
        });

        $purchase = SupplierPurchase::sole();
        $this->assertSame($purchase->reference, $results[0]['reference']);
        $this->assertSame($purchase->reference, $results[1]['reference']);
        $this->assertSame(2, array_sum(array_column($results, 'posts')));
        $this->assertSame(['posts' => 0, 'gets' => 0], $results[2]);
        $this->assertSame(0, array_sum(array_column($results, 'gets')));
        $this->assertSame(1, $purchase->replay_count);
        $this->assertSame('ambiguous', $purchase->state);
        $this->assertSame('dispatch_outcome_unknown', $purchase->last_error);
        $this->assertNull($purchase->claim_token);
        $this->assertNull($purchase->lease_expires_at);
        $this->assertNull($purchase->supplier_order_id);
        $this->assertNull($purchase->committed_at);
        $this->assertNull($purchase->actual_cost_micros);
        $this->assertSame([], $purchase->trusted_delivery_payload ?? []);
        $this->assertSame(['unknown', 'unknown'], DB::table('supplier_purchase_dispatches')->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(1, SupplierPurchase::count());
        $this->assertSame(0, ProductStockItem::count());
        $this->assertSame(10_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(10, $sku->fresh()->stock);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame('review', $fulfillment->fresh()->state);
        $this->assertSame($order->id, $fulfillment->fresh()->order_id);
        $this->assertSame($order->orderItems()->sole()->id, $fulfillment->fresh()->order_item_id);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
    }

    private function fixture(int $balance = 10_000_000, int $stock = 10): array
    {
        SupplierCurrencyRate::query()->firstOrCreate(
            ['currency' => 'USD'],
            ['exchange_rate_idr' => '1000.000000', 'version' => 1],
        );
        $supplier = Supplier::forceCreate([
            'name' => 'Synthetic Canboso', 'provider' => 'canboso',
            'api_key' => 'synthetic-canboso-buyer-key', 'currency' => 'USD',
            'currency_confirmed' => true, 'exchange_rate_idr' => 1000,
            'balance_micros' => $balance, 'is_active' => true,
            'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5),
        ]);
        $sku = SupplierCatalogItem::create([
            'supplier_id' => $supplier->id, 'external_id' => 'canboso-race-sku',
            'name' => 'Synthetic Canboso account', 'currency' => 'USD',
            'unit_price_micros' => 1_000_000, 'stock' => $stock,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(),
            'price_tiers' => [], 'bulk_discounts' => [],
        ]);
        $product = $this->mappedProduct($sku);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);

        return [$product, $supplier->fresh(), $sku->fresh(), $this->cart($product->id, 791001)];
    }

    private function mappedProduct(SupplierCatalogItem $sku): Product
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0, 'price_amount' => 5000]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);

        return $product;
    }

    private function cart(int $productId, int $telegramId): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $productId, 'quantity' => 1]);

        return $cart;
    }

    private function paid(Cart $cart): array
    {
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        app(OrderLifecycle::class)->applyManualCompletion($order);

        return [$order, SupplierFulfillment::where('order_id', $order->id)->sole()];
    }

    private function checkoutOutcome(int $cartId): array
    {
        try {
            $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cartId), CheckoutPaymentIntents::manualQris());

            return ['outcome' => 'reserved', 'order_id' => $order->id];
        } catch (InsufficientStock) {
            return ['outcome' => 'unavailable'];
        }
    }

    private function serializedCheckouts(Cart $first, Cart $second): array
    {
        return $this->withBarriers(function (string $directory) use ($first, $second): array {
            return $this->race([
                function () use ($first, $directory): array {
                    return DB::transaction(function () use ($first, $directory): array {
                        $result = $this->checkoutOutcome($first->id);
                        $this->assertSame('reserved', $result['outcome']);
                        touch($directory.'/reserved');
                        $this->awaitBarrier($directory.'/contender-pid');
                        $pid = (int) file_get_contents($directory.'/contender-pid');
                        $deadline = microtime(true) + 5;
                        do {
                            $blocked = DB::selectOne('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked', [$pid]);
                            if (in_array($blocked->blocked, [true, 1, '1', 't'], true)) {
                                return $result;
                            }
                            usleep(1000);
                        } while (microtime(true) < $deadline);
                        throw new RuntimeException('Competing checkout did not wait on the uncommitted checkout locks.');
                    });
                },
                function () use ($second, $directory): array {
                    $this->awaitBarrier($directory.'/reserved');
                    file_put_contents($directory.'/contender-pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);

                    return $this->checkoutOutcome($second->id);
                },
            ]);
        });
    }

    private function assertReservedWinner(Cart $winner, Cart $loser, Supplier $supplier, SupplierCatalogItem $sku, string $orderId): void
    {
        $order = Order::sole();
        $fulfillment = SupplierFulfillment::sole();
        $this->assertSame($orderId, $order->id);
        $this->assertSame($winner->customer_id, $order->customer_id);
        $this->assertSame($winner->id, $order->cart_id);
        $this->assertSame(0, Order::where('customer_id', $loser->customer_id)->count());
        $this->assertSame($order->id, $fulfillment->order_id);
        $this->assertSame($order->orderItems()->sole()->id, $fulfillment->order_item_id);
        $this->assertSame($supplier->id, $fulfillment->reserved_supplier_id);
        $this->assertSame($sku->id, $fulfillment->reserved_catalog_item_id);
        $this->assertSame(1, $fulfillment->reserved_quantity);
        $this->assertSame(1_000_000, $fulfillment->reserved_cost_micros);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, SupplierPurchase::count());
        $this->assertSame(0, ProductStockItem::count());
    }

    private function assertDelivered(Product $product, Supplier $supplier, SupplierCatalogItem $sku, Order $order, SupplierFulfillment $fulfillment): void
    {
        $purchase = SupplierPurchase::sole();
        $credential = ProductStockItem::sole();
        $secret = "synthetic-canboso@example.test\nsynthetic-canboso-password\nverifyEmail: recovery@example.test\notherInfo: synthetic-canboso-token";
        $this->assertSame('delivered', $purchase->state);
        $this->assertSame('CANBOSO-RACE-ORDER', $purchase->supplier_order_id);
        $this->assertSame(1_000_000, $purchase->actual_cost_micros);
        $this->assertNotNull($purchase->committed_at);
        $this->assertNull($purchase->claim_token);
        $this->assertNull($purchase->lease_expires_at);
        $this->assertSame([$secret], $purchase->delivery_payload);
        $this->assertSame([$secret], $purchase->trusted_delivery_payload);
        $this->assertSame($secret, $credential->secret);
        $this->assertStringNotContainsString('synthetic-canboso-password', $credential->getRawOriginal('secret'));
        $this->assertSame($purchase->id, $credential->supplier_purchase_id);
        $this->assertSame(1, $credential->supplier_unit_index);
        $this->assertSame($product->id, $credential->product_id);
        $this->assertSame($order->orderItems()->sole()->id, $credential->order_item_id);
        $this->assertSame($order->id, $credential->orderItem->order_id);
        $this->assertSame(0, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame('delivered', $fulfillment->fresh()->state);
        $this->assertSame(0, $fulfillment->fresh()->reserved_quantity);
        $this->assertSame(0, $fulfillment->fresh()->reserved_cost_micros);
        $this->assertSame(9_000_000, $supplier->fresh()->balance_micros);
        $this->assertSame(0, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(9, $sku->fresh()->stock);
        $this->assertSame(0, $sku->fresh()->reserved_quantity);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
    }

    private function assertPurchaseRequest($request): void
    {
        $this->assertSame('POST', $request->method());
        $this->assertSame('https://canboso.com/api/v2/telegram-buyer/purchase', $request->url());
        $this->assertSame('synthetic-canboso-buyer-key', $request['key']);
        $this->assertSame('canboso-race-sku', $request['product_id']);
        $this->assertSame(1, $request['quantity']);
        $this->assertNotEmpty($request->header('Idempotency-Key'));
        $this->assertSame(SupplierPurchase::sole()->reference, $request->header('Idempotency-Key')[0]);
    }

    private function purchasePayload(string $password = 'synthetic-canboso-password'): array
    {
        return [
            'success' => true,
            'order' => ['orderCode' => 'CANBOSO-RACE-ORDER', 'status' => 'completed',
                'productId' => 'canboso-race-sku', 'productType' => 'account',
                'quantity' => 1, 'bonusQuantity' => 0, 'finalQuantity' => 1],
            'payment' => ['amount' => '1.00', 'currency' => 'USD'],
            'delivery' => ['accounts' => [['user' => 'synthetic-canboso@example.test',
                'password' => $password, 'verifyEmail' => 'recovery@example.test',
                'otherInfo' => 'synthetic-canboso-token']]],
        ];
    }

    private function catalogPayload(int $stock = 10, string $name = 'Synthetic Canboso account'): array
    {
        return ['success' => true, 'walletCurrency' => 'USD', 'products' => [[
            'productId' => 'canboso-race-sku', 'name' => $name, 'productType' => 'account',
            'price' => ['amount' => '1.00', 'currency' => 'USD'],
            'availability' => ['available' => $stock], 'promotions' => [],
        ]]];
    }

    private function transportCounts(): array
    {
        return [
            'posts' => Http::recorded(fn ($request) => $request->method() === 'POST')->count(),
            'gets' => Http::recorded(fn ($request) => $request->method() === 'GET')->count(),
        ];
    }

    private function withBarriers(callable $callback): array
    {
        $directory = storage_path('framework/testing/canboso-barrier-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        try {
            return $callback($directory);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function awaitBarrier(string $path): void
    {
        $deadline = microtime(true) + 6;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (! is_file($path)) {
            throw new RuntimeException('Canboso race barrier was not reached: '.basename($path));
        }
    }
}
