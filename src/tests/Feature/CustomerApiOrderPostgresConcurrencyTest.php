<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\CartOrigin;
use App\Enums\CartStatus;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientUsdtBalance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerApiOrderClaim;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\CustomerApi\CustomerApiPrincipal;
use App\Services\OrderCheckoutService;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\RunsPostgresRace;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class CustomerApiOrderPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresRace, RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('Customer API orders');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_three_concurrent_matching_requests_yield_one_order_payment_debit_and_allocation(): void
    {
        $customer = $this->customer(6_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);

        $results = $this->runConcurrentWorkers(3, fn (): string => $this->apiPlace($principal, 'api-race', $product));
        $values = array_map(fn (array $result): string => (string) $result['value'], $results);
        $orderIds = $this->orderTokens($values);
        $this->assertCount(1, $orderIds, json_encode($results));
        foreach ($values as $value) {
            $this->assertTrue($value === 'err:in_progress' || $value === $orderIds[0], json_encode($results));
        }

        $order = Order::sole();
        $this->assertSame($orderIds[0], 'ok:'.$order->id);
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(4_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(1, Cart::where('origin', CartOrigin::Api)->count());
        $this->assertSame(CartStatus::CheckedOut, Cart::where('origin', CartOrigin::Api)->sole()->status);

        // A late replay after an edit still returns the same accepted Order.
        $product->update(['price_amount' => 99_000, 'usdt_price_amount' => 9_000_000]);
        $this->assertSame('ok:'.$order->id, $this->apiPlace($principal, 'api-race', $product));
        $this->assertSame(1, Order::count());
        $this->assertSame(4_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    public function test_changed_payload_on_the_same_key_conflicts_without_effects(): void
    {
        $customer = $this->customer(6_000_000);
        $product = $this->product(2);
        $principal = $this->principal($customer);

        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->apiPlace(
            $principal,
            'api-conflict',
            $product,
            quantity: $index + 1,
            expected: ['USD' => $index === 0 ? '2' : '4'],
        ));
        $values = array_map(fn (array $result): string => (string) $result['value'], $results);
        $tokens = $this->orderTokens($values);
        $this->assertCount(1, $tokens, json_encode($results));
        $this->assertSame(['err:idempotency_conflict'], array_values(array_diff($values, $tokens)), json_encode($results));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertContains(app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro(), [2_000_000, 4_000_000]);
        $this->assertSame(1, CustomerApiOrderClaim::where('idempotency_key', 'api-conflict')->count());
    }

    public function test_api_order_and_bot_checkout_contend_for_last_funds_and_last_stock(): void
    {
        $this->assertLastFundsOrdering(apiFirst: true);
        $this->assertLastFundsOrdering(apiFirst: false);
        $this->assertLastStockOrdering(apiFirst: true);
        $this->assertLastStockOrdering(apiFirst: false);
    }

    public function test_distinct_api_intents_and_bot_contend_for_one_funded_local_unit(): void
    {
        $customer = $this->customer(2_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);
        $botCart = $this->botCart($customer, $product);
        $results = $this->runConcurrentWorkers(3, fn (int $index): string => $index === 2
            ? $this->botPlace($botCart, 'three-way-bot')
            : $this->apiPlace($principal, 'three-way-api-'.$index, $product));
        $values = array_column($results, 'value');
        $this->assertCount(1, $this->orderTokens($values), json_encode($results));
        foreach ($values as $value) {
            $this->assertTrue(str_starts_with((string) $value, 'ok:')
                || in_array($value, ['err:insufficient_funds', 'err:stock_conflict'], true), json_encode($results));
        }
        $order = Order::sole();
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, $this->usdtPurchaseDebits([$customer->id]));
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, ProductStockItem::where('order_item_id', $order->orderItems()->sole()->id)->count());
        $this->assertSame(0, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, Cart::where('status', CartStatus::CheckedOut)->count());
        $this->assertSame($order->api_order_claim_id === null ? CartStatus::CheckedOut : CartStatus::Active, $botCart->fresh()->status);
    }

    public function test_distinct_customers_can_buy_after_both_api_carts_hold_product_foreign_keys(): void
    {
        $customers = [$this->customer(2_000_000), $this->customer(2_000_000)];
        $principals = array_map(fn (Customer $customer) => $this->principal($customer), $customers);
        $product = $this->product(2);
        $directory = $this->createWorkspace('api-cart-product-foreign-keys');

        try {
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($principals, $product, $directory): string {
                // Surface a lock inversion before PostgreSQL retries can mask it.
                DB::statement("SET deadlock_timeout TO '5s'");
                DB::statement("SET lock_timeout TO '2s'");
                CartItem::created(function () use ($index, $directory): void {
                    touch($directory.'/cart-'.$index);
                    $this->waitForMarker($directory.'/cart-'.(1 - $index));
                });

                return $this->apiPlace($principals[$index], 'foreign-key-race-'.$index, $product);
            });
        } finally {
            $this->cleanupWorkspace($directory);
        }

        $this->assertCount(2, $this->orderTokens(array_column($results, 'value')), json_encode($results));
        $this->assertSame(2, DB::table('payments')->count());
        $this->assertSame(2, $this->usdtPurchaseDebits(array_map(fn (Customer $customer) => $customer->id, $customers)));
        foreach ($customers as $customer) {
            $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        }
        $this->assertSame(2, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    /**
     * One Customer holding exactly one order's funds, contended by an API order and
     * that Customer's bot cart. A marker handshake forces both orderings, so the
     * outcome is proven for a winning API and a winning bot.
     */
    private function assertLastFundsOrdering(bool $apiFirst): void
    {
        $label = $apiFirst ? 'funds-api-first' : 'funds-bot-first';
        $customer = $this->customer(2_000_000);
        $product = $this->product(2);
        $botCart = $this->botCart($customer, $product);
        $principal = $this->principal($customer);
        $bot = fn (): string => $this->botPlace($botCart, $label.'-bot');
        $api = fn (): string => $this->apiPlace($principal, $label.'-api', $product);
        $results = $this->orderedRace($label, $apiFirst ? $api : $bot, $apiFirst ? $bot : $api);
        $values = array_column($results, 'value');
        $tokens = $this->orderTokens($values);
        $this->assertCount(1, $tokens, $label.' '.json_encode($results));
        $losers = array_values(array_diff($values, $tokens));
        $this->assertCount(1, $losers, $label.' '.json_encode($results));
        $this->assertContains($losers[0], ['err:insufficient_funds', 'err:stock_conflict'], $label.' '.json_encode($results));
        // The forced ordering holds: the first purchase consumed the last funds.
        $this->assertSame($tokens[0], (string) $results[0]['value'], $label.' first worker must win');

        $winner = Order::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame($tokens[0], 'ok:'.$winner->getKey(), $label);
        $this->assertSame(2_000_000, (int) $winner->payments()->sole()->amount, $label);
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro(), $label);
        $this->assertSame(1, $this->usdtPurchaseDebits([$customer->id]), $label);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count(), $label);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count(), $label);
        $this->assertSame(0, $product->fresh()->reserved_stock, $label);
        // The loser left nothing behind: no Cart from a rolled-back API attempt,
        // an untouched bot cart, and only the winner owns an Order.
        $this->assertSame($apiFirst ? 1 : 0, Cart::query()->where('customer_id', $customer->id)->where('origin', CartOrigin::Api)->count(), $label);
        $this->assertSame($apiFirst ? CartStatus::CheckedOut : null, Cart::query()->where('customer_id', $customer->id)->where('origin', CartOrigin::Api)->first()?->status, $label);
        $this->assertSame($apiFirst ? CartStatus::Active : CartStatus::CheckedOut, $botCart->fresh()->status, $label);
        $this->assertSame($apiFirst ? 0 : 1, Order::query()->where('cart_id', $botCart->getKey())->count(), $label);
        $apiClaim = CustomerApiOrderClaim::query()->where('idempotency_key', $label.'-api')->sole();
        $this->assertSame($apiFirst ? CustomerApiOrderClaim::STATE_ACCEPTED : CustomerApiOrderClaim::STATE_IN_PROGRESS, $apiClaim->state, $label);
        $this->assertSame($apiFirst ? (string) $winner->getKey() : null, $apiClaim->order_id, $label);
    }

    /**
     * One Product holding a single unit, contended by an API order and a bot cart
     * owned by two different Customers. The marker handshake forces both orderings,
     * so the losing Customer's wallet is provably left exactly untouched.
     */
    private function assertLastStockOrdering(bool $apiFirst): void
    {
        $label = $apiFirst ? 'stock-api-first' : 'stock-bot-first';
        $product = $this->product(1);
        $apiCustomer = $this->customer(5_000_000);
        $botCustomer = $this->customer(5_000_000);
        $botCart = $this->botCart($botCustomer, $product);
        $principal = $this->principal($apiCustomer);
        $bot = fn (): string => $this->botPlace($botCart, $label.'-bot');
        $api = fn (): string => $this->apiPlace($principal, $label.'-api', $product);
        $results = $this->orderedRace($label, $apiFirst ? $api : $bot, $apiFirst ? $bot : $api);
        $values = array_column($results, 'value');
        $tokens = $this->orderTokens($values);
        $this->assertCount(1, $tokens, $label.' '.json_encode($results));
        $losers = array_values(array_diff($values, $tokens));
        $this->assertCount(1, $losers, $label.' '.json_encode($results));
        $this->assertSame('err:stock_conflict', $losers[0], $label.' '.json_encode($results));
        $this->assertSame($tokens[0], (string) $results[0]['value'], $label.' first worker must win');

        $winner = Order::query()->whereIn('customer_id', [$apiCustomer->id, $botCustomer->id])->sole();
        $this->assertSame($tokens[0], 'ok:'.$winner->getKey(), $label);
        // The winner paid exactly one unit; the loser's wallet is untouched.
        $this->assertSame($apiFirst ? 3_000_000 : 5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($apiCustomer)->balanceMicro(), $label);
        $this->assertSame($apiFirst ? 5_000_000 : 3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($botCustomer)->balanceMicro(), $label);
        $this->assertSame(1, $this->usdtPurchaseDebits([$apiCustomer->id, $botCustomer->id]), $label);
        $this->assertSame(1, ProductStockItem::where('product_id', $product->id)->whereNotNull('order_item_id')->count(), $label);
        $this->assertSame(0, ProductStockItem::where('product_id', $product->id)->whereNull('order_item_id')->count(), $label);
        $this->assertSame(0, $product->fresh()->stock, $label);
        $this->assertSame(0, $product->fresh()->reserved_stock, $label);
        $this->assertSame($apiFirst ? 1 : 0, Cart::query()->where('customer_id', $apiCustomer->id)->where('origin', CartOrigin::Api)->count(), $label);
        $this->assertSame($apiFirst ? 0 : 1, Order::query()->where('cart_id', $botCart->getKey())->count(), $label);
        $this->assertSame($apiFirst ? CartStatus::Active : CartStatus::CheckedOut, $botCart->fresh()->status, $label);
        $apiClaim = CustomerApiOrderClaim::query()->where('idempotency_key', $label.'-api')->sole();
        $this->assertSame($apiFirst ? CustomerApiOrderClaim::STATE_ACCEPTED : CustomerApiOrderClaim::STATE_IN_PROGRESS, $apiClaim->state, $label);
        $this->assertSame($apiFirst ? (string) $winner->getKey() : null, $apiClaim->order_id, $label);
    }

    public function test_stale_expected_price_never_charges_a_wallet_during_a_price_edit(): void
    {
        $customer = $this->customer(5_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);

        $results = $this->runConcurrentWorkers(2, function (int $index) use ($product, $principal): string {
            if ($index === 1) {
                DB::table('products')->where('id', $product->id)->update(['usdt_price_amount' => 3_000_000]);
                $product->refresh();

                return 'edited';
            }

            return $this->apiPlace($principal, 'api-price', $product);
        });
        $purchase = $results[0]['value'];
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $orders = Order::all();
        if ($orders->isEmpty()) {
            $this->assertSame('err:price_changed', $purchase, json_encode($results));
            $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
            $this->assertSame(0, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
            $this->assertSame(1, ProductStockItem::whereNull('order_item_id')->count());
        } else {
            // The purchase used one complete locked snapshot at the old price.
            $this->assertSame(2_000_000, (int) $orders->sole()->payments()->sole()->amount);
            $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
            $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
            $this->assertSame(0, $product->fresh()->stock);
        }
    }

    public function test_admin_tier_and_reseller_edits_racing_api_checkout_cannot_charge_unacknowledged_prices(): void
    {
        foreach ([false, true] as $reseller) {
            $customer = $this->customer(8_000_000);
            $product = $this->product(2);
            $product->update(['usdt_price_amount' => 4_000_000, 'reseller_usdt_price_amount' => $reseller ? 2_000_000 : null]);
            $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 12_000, 'usdt_price_amount' => 2_000_000]);
            $principal = $this->principal($customer);
            $admin = User::factory()->create(['role' => UserRole::Owner]);
            $payload = [
                'workspace_section' => 'price',
                'workspace_version' => app(AdminProductWorkspaceData::class)->version($product->fresh(), 'price'),
                'price_amount' => 20_000,
                'usdt_price_amount' => '5',
                'reseller_usdt_price_amount' => $reseller ? '3' : null,
                'quantity_price_tiers_present' => true,
                'quantity_price_tiers' => [['minimum_quantity' => 2, 'price_amount' => 18_000, 'usdt_price_amount' => '3.5']],
            ];
            $key = $reseller ? 'reseller-edit' : 'tier-edit';
            $results = $this->runConcurrentWorkers(2, function (int $index) use ($admin, $product, $payload, $principal, $key): string {
                if ($index === 0) {
                    $this->actingAs($admin);

                    return (string) $this->patchJson(route('admin.products.update', $product->id), $payload)->getStatusCode();
                }

                return $this->apiPlace($principal, $key, $product, quantity: 2, expected: ['USD' => '4']);
            });
            $this->assertSame('200', $results[0]['value'], json_encode($results));
            $order = Order::where('customer_id', $customer->id)->first();
            if ($order === null) {
                $this->assertSame('err:price_changed', $results[1]['value'], json_encode($results));
                $this->assertSame(8_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
                $this->assertSame(0, $this->usdtPurchaseDebits([$customer->id]));
                $this->assertSame(2, $product->fresh()->stock);
            } else {
                $this->assertSame('ok:'.$order->id, $results[1]['value'], json_encode($results));
                $this->assertSame(4_000_000, (int) $order->payments()->sole()->amount);
                $this->assertSame(4_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
                $this->assertSame(1, $this->usdtPurchaseDebits([$customer->id]));
                $this->assertSame(2, ProductStockItem::where('order_item_id', $order->orderItems()->sole()->id)->count());
            }
            $this->assertSame(0, $product->fresh()->reserved_stock);
        }
    }

    public function test_revocation_waits_for_a_fenced_api_order_and_later_mutations_are_rejected(): void
    {
        $customer = $this->customer(5_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);
        $directory = $this->createWorkspace('api-revoke');
        try {
            $results = $this->race([
                function () use ($principal, $product, $directory): string {
                    Cart::creating(function () use ($directory): void {
                        touch($directory.'/fenced');
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/revoking') && microtime(true) < $deadline) {
                            usleep(5000);
                        }
                        usleep(200000);
                    });

                    return $this->apiPlace($principal, 'api-revoke', $product);
                },
                function () use ($customer, $directory): string {
                    $this->waitForMarker($directory.'/fenced');
                    touch($directory.'/revoking');
                    app(CustomerApiCredentials::class)->disable($customer);

                    return 'revoked';
                },
            ]);
            $this->assertSame(['revoked'], array_values(array_filter($results, fn (string $value): bool => $value === 'revoked')));
            $this->assertSame('ok:'.Order::sole()->id, $results[0]);
        } finally {
            $this->cleanupWorkspace($directory);
        }

        // The pre-revocation fenced transaction finished exactly once, and no
        // mutation admitted after revocation can consume the wallet.
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame('err:unauthenticated', $this->apiPlace($principal, 'api-after-revoke', $this->product(1)));
        $this->assertSame(1, Order::count());
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    public function test_abandoned_claim_is_taken_over_exactly_once_after_its_lease_expires(): void
    {
        $customer = $this->customer(6_000_000);
        $product = $this->product(2);
        $principal = $this->principal($customer);
        $request = $this->request('api-abandoned', $product);
        $claim = CustomerApiOrderClaim::create([
            'customer_id' => $customer->id,
            'operation' => CustomerApiOrderClaim::OPERATION_CREATE_ORDER,
            'idempotency_key' => 'api-abandoned',
            'fingerprint' => $request->fingerprint(),
            'credential_id' => $principal->credentialId,
            'state' => CustomerApiOrderClaim::STATE_IN_PROGRESS,
            'attempt_token' => str_repeat('a', 32),
            'lease_expires_at' => now()->subMinute(),
            'preference' => BalancePaymentPreference::UsdFirst->value,
            'preference_source' => 'account',
            'product_id' => $product->id,
            'quantity' => 1,
            'expected_usd_total_micro' => 2_000_000,
        ]);

        // A live lease is never stolen, even for an identical payload.
        DB::table('customer_api_order_claims')->where('id', $claim->id)->update(['lease_expires_at' => now()->addMinute()]);
        $this->assertSame('err:in_progress', $this->apiPlace($principal, 'api-abandoned', $product));
        $this->assertSame(CustomerApiOrderClaim::STATE_IN_PROGRESS, $claim->fresh()->state);
        $this->assertSame(0, Order::count());

        DB::table('customer_api_order_claims')->where('id', $claim->id)->update(['lease_expires_at' => now()->subMinute()]);
        $results = $this->runConcurrentWorkers(3, fn (): string => $this->apiPlace($principal, 'api-abandoned', $product));
        $values = array_map(fn (array $result): string => (string) $result['value'], $results);
        $orderIds = $this->orderTokens($values);
        $this->assertCount(1, $orderIds, json_encode($results));
        foreach ($values as $value) {
            $this->assertTrue($value === 'err:in_progress' || $value === $orderIds[0], json_encode($results));
        }
        $this->assertSame(1, Order::count());
        $this->assertSame($orderIds[0], 'ok:'.Order::sole()->id);
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(4_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(CustomerApiOrderClaim::STATE_ACCEPTED, $claim->fresh()->state);
        $this->assertSame(Order::sole()->id, $claim->fresh()->order_id);
        $this->assertSame(1, CustomerApiOrderClaim::count());
    }

    public function test_process_death_before_commit_rolls_back_and_the_abandoned_claim_recovers(): void
    {
        $this->assertCrashRecovery(afterCommit: false);
    }

    public function test_process_death_after_commit_replays_the_same_order_after_key_rotation(): void
    {
        $this->assertCrashRecovery(afterCommit: true);
    }

    private function assertCrashRecovery(bool $afterCommit): void
    {
        $customer = $this->customer(5_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);
        $results = $this->runConcurrentWorkers(1, function () use ($principal, $product, $afterCommit): string {
            Order::created(function () use ($afterCommit): void {
                $kill = fn () => posix_kill(getmypid(), SIGKILL);
                if ($afterCommit) {
                    DB::afterCommit($kill);
                } else {
                    $kill();
                }
            });

            return $this->apiPlace($principal, 'crashed-order', $product);
        });
        $this->assertSame(SIGKILL, $results[0]['signal']);
        $claim = CustomerApiOrderClaim::sole();
        $acceptedId = $claim->order_id;
        $this->assertSame($afterCommit ? 1 : 0, Order::count());
        $this->assertSame($afterCommit ? 1 : 0, DB::table('payments')->count());
        $this->assertSame($afterCommit ? 1 : 0, $this->usdtPurchaseDebits([$customer->id]));
        $this->assertSame($afterCommit ? 1 : 0, Cart::count());
        $this->assertSame($afterCommit ? 3_000_000 : 5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame($afterCommit ? 0 : 1, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);

        if (! $afterCommit) {
            $this->assertNull($acceptedId);
            DB::table('customer_api_order_claims')->where('id', $claim->id)->update(['lease_expires_at' => now()->subMinute()]);
        } else {
            $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::IdrOnly])->save();
            $product->update(['usdt_price_amount' => 9_000_000, 'reseller_usdt_price_amount' => 7_000_000, 'is_active' => false]);
        }
        $credentials = app(CustomerApiCredentials::class);
        $confirmation = $credentials->prepareRotation($customer, 123);
        $principal = $credentials->authenticate('Bearer '.$credentials->rotate($customer, 123, $confirmation)->takeSecret());
        $result = app(CustomerApiOrderCheckout::class)->place($principal, $this->request('crashed-order', $product));
        $this->assertSame($afterCommit, $result->replayed);
        $this->assertSame($acceptedId ?? $result->order->id, $result->order->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, $this->usdtPurchaseDebits([$customer->id]));
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(1, Cart::count());
        $this->assertSame(CustomerApiOrderClaim::STATE_ACCEPTED, $claim->fresh()->state);
        $this->assertSame($result->order->id, $claim->fresh()->order_id);
        $this->assertSame('USDT', $result->order->currency);
        $this->assertSame(2_000_000, (int) $result->order->payments()->sole()->amount);
    }

    public function test_database_rejects_claim_identity_and_api_origin_relabeling(): void
    {
        $customer = $this->customer(5_000_000);
        $product = $this->product(1);
        $principal = $this->principal($customer);
        $this->apiPlace($principal, 'api-immutable', $product);
        $claim = CustomerApiOrderClaim::sole();
        $order = Order::sole();

        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_api_order_claims')->where('id', $claim->id)->update(['quantity' => 2]),
            'Customer API order claim identity is immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_api_order_claims')->where('id', $claim->id)->update(['order_id' => null]),
            'Customer API order claim acceptance is immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('customer_api_order_claims')->where('id', $claim->id)->delete(),
            'Customer API order claim history is immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('orders')->where('id', $order->id)->update(['api_order_claim_id' => null]),
            'Order API origin is immutable',
        );
        $this->assertDatabaseConstraintRejects(
            fn () => DB::table('carts')->where('origin', CartOrigin::Api)->update(['origin' => 'customer']),
            'Cart origin is immutable',
        );
        $this->assertSame(CartOrigin::Api, Cart::where('origin', CartOrigin::Api)->sole()->origin);
    }

    /**
     * USDT purchase debits for the given Customers. The ledger entry is account
     * scoped, so the count joins through each Customer's balance account.
     *
     * @param  list<int>  $customerIds
     */
    private function usdtPurchaseDebits(array $customerIds): int
    {
        return DB::table('customer_usdt_balance_entries')
            ->join('customer_usdt_balance_accounts', 'customer_usdt_balance_accounts.id', '=', 'customer_usdt_balance_entries.customer_usdt_balance_account_id')
            ->whereIn('customer_usdt_balance_accounts.customer_id', $customerIds)
            ->where('customer_usdt_balance_entries.kind', 'purchase_debit')
            ->count();
    }

    /**
     * Start the second purchase while the first holds its wallet/inventory locks.
     * This proves both winner orderings with overlapping transactions.
     *
     * @param  Closure(): string  $first
     * @param  Closure(): string  $second
     * @return list<array{ok: bool, value?: string, class?: string, exit_code: int, signal?: int|null}>
     */
    private function orderedRace(string $label, Closure $first, Closure $second): array
    {
        $directory = $this->createWorkspace('api-order-race-'.$label);
        try {
            return $this->runConcurrentWorkers(2, function (int $index) use ($directory, $first, $second): string {
                if ($index === 0) {
                    Order::creating(function () use ($directory): void {
                        touch($directory.'/first-locked');
                        $this->waitForMarker($directory.'/second-starting');
                        usleep(200_000);
                    });
                    $token = $first();

                    return $token;
                }

                $this->waitForMarker($directory.'/first-locked');
                touch($directory.'/second-starting');

                return $second();
            });
        } finally {
            $this->cleanupWorkspace($directory);
        }
    }

    /** Bot checkout reported as a token so both sides of a contended race are symmetric. */
    private function botPlace(Cart $cart, string $key): string
    {
        try {
            return 'ok:'.app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::usdtBalance(), $key)->getKey();
        } catch (InsufficientStock) {
            return 'err:stock_conflict';
        } catch (InsufficientUsdtBalance) {
            return 'err:insufficient_funds';
        }
    }

    private function apiPlace(
        CustomerApiPrincipal $principal,
        string $key,
        Product $product,
        int $quantity = 1,
        ?array $expected = null,
    ): string {
        try {
            $result = app(CustomerApiOrderCheckout::class)->place(
                $principal,
                $this->request($key, $product, $quantity, $expected),
            );

            return 'ok:'.$result->order->getKey();
        } catch (CustomerApiFailure $failure) {
            return 'err:'.$failure->machineCode;
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function orderTokens(array $values): array
    {
        return array_values(array_unique(array_filter($values, fn (string $value): bool => str_starts_with($value, 'ok:'))));
    }

    private function request(string $key, Product $product, int $quantity = 1, ?array $expected = null): CustomerApiOrderRequest
    {
        $body = json_encode([
            'product_id' => (string) $product->id,
            'quantity' => $quantity,
            'expected_totals' => $expected ?? ['USD' => '2'],
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->headers->set('Idempotency-Key', $key);
        $request->attributes->set('customer_api_request_id', 'request-fixture');

        return CustomerApiOrderRequest::fromRequest($request);
    }

    private function principal(Customer $customer): CustomerApiPrincipal
    {
        $credentials = app(CustomerApiCredentials::class);

        return $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
    }

    private function customer(int $funds = 5_000_000): Customer
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::UsdFirst])->save();
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits($funds), 'api-fixture-'.$customer->id, 'api-receipt-'.$customer->id, 'v1'), 'api-fixture-'.$customer->id);

        return $customer;
    }

    private function product(int $stock): Product
    {
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_000, 'stock' => $stock, 'reserved_stock' => 0]);
        for ($index = 0; $index < $stock; $index++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'api-race-secret-'.$index]);
        }

        return $product;
    }

    private function botCart(Customer $customer, Product $product): Cart
    {
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'origin' => CartOrigin::Customer, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        return $cart;
    }
}
