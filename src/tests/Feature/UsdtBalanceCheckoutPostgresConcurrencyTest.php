<?php

namespace Tests\Feature;

use App\Enums\BalancePaymentPreference;
use App\Enums\CartStatus;
use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\CustomerUsdtFundingUnavailable;
use App\Exceptions\InsufficientCheckoutBalance;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientStoreBalance;
use App\Exceptions\InsufficientUsdtBalance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Checkout\BalanceCheckoutRequest;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Operations\BalanceRestoreGateService;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RunsPostgresWorkers;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('concurrency')]
#[Group('postgres-release')]
final class UsdtBalanceCheckoutPostgresConcurrencyTest extends TestCase
{
    use RunsPostgresWorkers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePostgresConcurrency('USDT checkout');
        $this->assertSame(0, PostgresTestDatabase::migrateFresh());
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_two_orders_compete_for_last_customer_funds_without_stock_or_money_leak(): void
    {
        $customer = $this->customer(2_000_000);
        $product = $this->product(2);
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->checkout($carts[$index]->id, 'funds-'.$index));
        $this->assertOneWinner($results, InsufficientUsdtBalance::class);
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::whereNull('order_item_id')->count());
        $this->assertSame(1, Cart::where('status', CartStatus::Active)->count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
    }

    public function test_three_workers_and_lost_response_replay_return_the_same_order_and_debit(): void
    {
        $customer = $this->customer(6_000_000);
        $product = $this->product(1);
        $cart = $this->cart($customer, $product);
        $results = $this->runConcurrentWorkers(3, fn (): string => $this->checkout($cart->id, 'one-checkout'));
        $this->assertWorkersSucceeded($results);
        $ids = array_column($results, 'value');
        $this->assertSame([$ids[0], $ids[0], $ids[0]], $ids);
        $this->assertSame($ids[0], $this->checkout($cart->id, 'one-checkout'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(4_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_different_accounts_compete_for_last_local_stock_without_charging_loser(): void
    {
        $customers = [$this->customer(), $this->customer()];
        $product = $this->product(1);
        $carts = array_map(fn (Customer $customer): Cart => $this->cart($customer, $product), $customers);
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->checkout($carts[$index]->id, 'stock-'.$index));
        $this->assertOneWinner($results, InsufficientStock::class);
        $balances = array_map(fn (Customer $customer): int => app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro(), $customers);
        sort($balances);
        $this->assertSame([3_000_000, 5_000_000], $balances);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_supplier_last_wallet_capacity_is_reserved_once_without_network_inside_checkout(): void
    {
        $product = $this->product(0);
        $supplier = Supplier::forceCreate(['name' => 'Private test supplier', 'provider' => 'prodseller', 'api_key' => 'fixture-secret', 'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => 1000, 'balance_micros' => 1_000_000, 'is_active' => true, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $sku = SupplierCatalogItem::create(['supplier_id' => $supplier->id, 'external_id' => 'race-sku', 'name' => 'Test SKU', 'unit_price_micros' => 1_000_000, 'stock' => 10, 'orderable' => true, 'requires_input' => false, 'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => []]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $sku->id, 'is_active' => true, 'priority' => 0]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $customers = [$this->customer(), $this->customer()];
        $carts = array_map(fn (Customer $customer): Cart => $this->cart($customer, $product), $customers);
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->checkout($carts[$index]->id, 'supplier-'.$index));
        $this->assertOneWinner($results, InsufficientStock::class);
        $this->assertSame(1_000_000, $supplier->fresh()->reserved_balance_micros);
        $this->assertSame(1, $sku->fresh()->reserved_quantity);
        $this->assertSame(1, SupplierFulfillment::count());
        $this->assertSame(0, SupplierPurchase::count());
        $this->assertSame(1, Order::where('payment_status', PaymentStatus::Paid)->count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $balances = array_map(fn (Customer $customer): int => app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro(), $customers);
        sort($balances);
        $this->assertSame([3_000_000, 5_000_000], $balances);
    }

    public function test_postgres_accounting_insert_failure_rolls_back_debit_and_stock_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(1);
        $cart = $this->cart($customer, $product);
        DB::unprepared("CREATE FUNCTION issue555_reject_purchase() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.credit_account = 'usdt_sale' THEN RAISE EXCEPTION 'injected accounting failure'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER issue555_reject_purchase BEFORE INSERT ON customer_usdt_balance_accounting_events FOR EACH ROW EXECUTE FUNCTION issue555_reject_purchase();");
        try {
            $this->checkout($cart->id, 'accounting-fault');
            $this->fail('Expected PostgreSQL accounting failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected accounting failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER issue555_reject_purchase ON customer_usdt_balance_accounting_events; DROP FUNCTION issue555_reject_purchase();');
        }
        $this->assertSame(5_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull($product->stockItems()->sole()->order_item_id);
        $this->assertSame(CartStatus::Active, $cart->fresh()->status);
    }

    public static function spendingControls(): array
    {
        return [['pause', 'spending_paused'], ['restore', 'restore_gate_open']];
    }

    #[DataProvider('spendingControls')]
    public function test_control_writer_fences_both_preferences_before_any_debit(string $control, string $reason): void
    {
        $customer = $this->customer(2_000_000);
        $this->fundIdr($customer, 15_000);
        $product = $this->product(2);
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];
        $owner = User::factory()->create();
        $directory = $this->createWorkspace('dual-balance-control');
        try {
            $results = $this->runConcurrentWorkers(3, function (int $index) use ($control, $carts, $owner, $directory): string {
                if ($index === 0) {
                    DB::transaction(function () use ($control, $owner, $directory): void {
                        if ($control === 'pause') {
                            app(CustomerUsdtFundingPolicyManager::class)->pause(CustomerUsdtFundingPauseScope::Spending, 'Race incident', $owner, null);
                        } else {
                            app(BalanceRestoreGateService::class)->open(now()->subHour(), now(), 'Race restore', 'fixture-owner');
                        }
                        touch($directory.'/writer-holds-control');
                        $deadline = microtime(true) + 10;
                        do {
                            DB::select('SELECT pg_stat_clear_snapshot()');
                            $blocked = DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE datname = current_database() AND application_name IN ('issue620-reader-1','issue620-reader-2') AND cardinality(pg_blocking_pids(pid)) > 0");
                            if ((int) $blocked->total === 2) {
                                return;
                            }
                            usleep(10_000);
                        } while (microtime(true) < $deadline);
                        throw new \RuntimeException('Both checkouts must wait behind uncommitted control state.');
                    });

                    return 'control_committed';
                }
                DB::statement("SET application_name = 'issue620-reader-{$index}'");
                $this->waitForMarker($directory.'/writer-holds-control');
                try {
                    $this->preferenceCheckout($carts[$index - 1]->id, $index === 1 ? BalancePaymentPreference::IdrFirst : BalancePaymentPreference::UsdFirst);
                } catch (CustomerUsdtFundingUnavailable $exception) {
                    return $exception->reason->value;
                }

                throw new \RuntimeException('Checkout bypassed the financial control.');
            });
        } finally {
            $this->cleanupWorkspace($directory);
        }
        $this->assertWorkersSucceeded($results);
        $this->assertSame(['control_committed', $reason, $reason], array_column($results, 'value'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(15_000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(2_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(0, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_opposite_preferences_create_empty_secondary_account_without_lock_inversion(): void
    {
        $customer = Customer::factory()->create();
        $this->fundIdr($customer, 15_000);
        $product = $this->product(2);
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->preferenceCheckout($carts[$index]->id, $index === 0 ? BalancePaymentPreference::IdrFirst : BalancePaymentPreference::UsdFirst));
        $this->assertOneWinner($results, InsufficientCheckoutBalance::class);
        $this->assertSame(1, Order::count());
        $this->assertSame('IDR', Order::sole()->currency);
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_opposite_automatic_preferences_and_explicit_bot_compete_for_one_purchase(): void
    {
        $customer = $this->customer(1);
        $this->fundIdr($customer, 15_000);
        $product = $this->product(3);
        $carts = array_map(fn (): Cart => $this->cart($customer, $product), range(1, 3));
        $results = $this->runConcurrentWorkers(3, function (int $index) use ($carts): string {
            if ($index === 2) {
                return (string) app(OrderCheckoutService::class)->checkout(Cart::findOrFail($carts[$index]->id), CheckoutPaymentIntents::storeBalance(), 'explicit-bot')->id;
            }

            return $this->preferenceCheckout($carts[$index]->id, $index === 0 ? BalancePaymentPreference::IdrFirst : BalancePaymentPreference::UsdFirst);
        });
        $this->assertCount(1, array_filter($results, fn (array $row): bool => $row['ok']), json_encode($results));
        foreach (array_filter($results, fn (array $row): bool => ! $row['ok']) as $row) {
            $this->assertContains($row['class'], [InsufficientCheckoutBalance::class, InsufficientStoreBalance::class]);
        }
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, Order::count());
        $this->assertSame(1, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(0, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(2, Cart::where('status', CartStatus::Active)->count());
    }

    public function test_opposite_preferences_spend_two_wallets_without_split_or_deadlock(): void
    {
        $customer = $this->customer(2_000_000);
        $this->fundIdr($customer, 15_000);
        $product = $this->product(2);
        $carts = [$this->cart($customer, $product), $this->cart($customer, $product)];
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->preferenceCheckout($carts[$index]->id, $index === 0 ? BalancePaymentPreference::IdrFirst : BalancePaymentPreference::UsdFirst));
        $this->assertWorkersSucceeded($results);
        $this->assertSame(['IDR', 'USDT'], Order::orderBy('currency')->pluck('currency')->all());
        $this->assertSame(0, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, DB::table('store_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::PurchaseDebit)->count());
        $this->assertSame(2, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_automatic_requests_compete_for_last_stock_and_do_not_charge_loser(): void
    {
        $customers = [$this->customer(), $this->customer()];
        foreach ($customers as $customer) {
            $this->fundIdr($customer, 30_000);
        }
        $product = $this->product(1);
        $carts = array_map(fn (Customer $customer): Cart => $this->cart($customer, $product), $customers);
        $results = $this->runConcurrentWorkers(2, fn (int $index): string => $this->preferenceCheckout($carts[$index]->id, $index === 0 ? BalancePaymentPreference::IdrFirst : BalancePaymentPreference::UsdFirst));
        $this->assertOneWinner($results, InsufficientStock::class);
        $order = Order::with('payments')->sole();
        foreach ($customers as $customer) {
            $won = (int) $order->customer_id === (int) $customer->id;
            $this->assertSame(30_000 - ($won && $order->currency === 'IDR' ? 15_000 : 0), app(StoreBalanceLedger::class)->snapshot($customer)->balance());
            $this->assertSame(5_000_000 - ($won && $order->currency === 'USDT' ? 2_000_000 : 0), app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        }
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_automatic_spend_contends_with_original_refund_and_both_currency_topups(): void
    {
        $customer = $this->customer(2_000_000);
        $this->fundIdr($customer, 15_000);
        $product = $this->product(2);
        $old = app(OrderCheckoutService::class)->checkout($this->cart($customer, $product), CheckoutPaymentIntents::usdtBalance());
        $cart = $this->cart($customer, $product);
        $paymentId = $old->payments->sole()->id;
        $results = $this->runConcurrentWorkers(5, function (int $index) use ($customer, $cart, $paymentId): string {
            if ($index === 0) {
                return $this->preferenceCheckout($cart->id, BalancePaymentPreference::UsdFirst);
            }
            if ($index === 1 || $index === 4) {
                app(OrderLifecycle::class)->applyPaymentRefunded(Payment::findOrFail($paymentId));
            } elseif ($index === 2) {
                app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
                    new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(2_000_000), 'contended-usd', 'receipt-contended', 'v1'), 'contended-usd');
            } else {
                app(StoreBalanceLedger::class)->creditTopUp($customer, 15_000, 'contended-idr', 'contended-idr');
            }
            Http::assertNothingSent();

            return 'posted';
        });
        $this->assertWorkersSucceeded($results);
        $purchase = Order::findOrFail($results[0]['value']);
        $this->assertSame(30_000 - ($purchase->currency === 'IDR' ? 15_000 : 0), app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(4_000_000 - ($purchase->currency === 'USDT' ? 2_000_000 : 0), app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(2, Order::count());
        $this->assertSame(PaymentStatus::Paid, $purchase->payment_status);
        $this->assertSame(PaymentStatus::Refunded, $old->fresh()->payment_status);
        $this->assertSame(1, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::RefundCredit)->count());
        $this->assertSame(2_000_000, CustomerUsdtBalanceEntry::where('kind', UsdtBalanceEntryKind::RefundCredit)->sole()->amount_micro);
        $this->assertSame(2, ProductStockItem::whereNotNull('order_item_id')->count());
        $this->assertSame(1, ProductStockItem::whereNotNull('retired_at')->count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    public function test_three_automatic_replays_preserve_account_resolution_after_preference_edit(): void
    {
        $customer = $this->customer(2_000_000);
        $this->fundIdr($customer, 1);
        $product = $this->product(1);
        $cart = $this->cart($customer, $product);
        $work = fn (): string => $this->preferenceCheckout($cart->id, null);
        $results = $this->runConcurrentWorkers(3, $work);
        $this->assertWorkersSucceeded($results);
        $this->assertSame([$results[0]['value'], $results[0]['value'], $results[0]['value']], array_column($results, 'value'));
        $customer->forceFill(['balance_payment_preference' => BalancePaymentPreference::IdrOnly])->save();
        $product->update(['usdt_price_amount' => null, 'price_amount' => 99_000]);
        $this->assertSame($results[0]['value'], $work());
        $order = Order::sole();
        $this->assertSame('idr_first', $order->balance_checkout_snapshot['preference']);
        $this->assertSame('account', $order->balance_checkout_snapshot['source']);
        $this->assertSame('USDT', $order->currency);
        $this->assertSame(2_000_000, $order->payments()->sole()->amount);
        $this->assertSame(0, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
        $this->assertSame(1, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, ProductStockItem::whereNotNull('order_item_id')->count());
    }

    public function test_database_rejects_invalid_preference_and_snapshot_relabeling(): void
    {
        $customer = $this->customer();
        $product = $this->product(1);
        $order = Order::findOrFail($this->preferenceCheckout($this->cart($customer, $product)->id, BalancePaymentPreference::UsdOnly));
        $this->assertDatabaseConstraintRejects(fn () => DB::table('customers')->where('id', $customer->id)->update(['balance_payment_preference' => 'mixed']), 'customers_balance_payment_preference_check');
        $this->assertDatabaseConstraintRejects(fn () => DB::table('orders')->where('id', $order->id)->update(['balance_checkout_snapshot' => json_encode(['currency' => 'USD'])]), 'balance_checkout_snapshot is immutable');
        $this->assertSame('USDT', $order->fresh()->balance_checkout_snapshot['currency']);
        $this->assertSame(3_000_000, app(CustomerUsdtBalanceLedger::class)->snapshot($customer)->balanceMicro());
    }

    private function preferenceCheckout(string $cartId, ?BalancePaymentPreference $preference): string
    {
        $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cartId), checkoutKey: 'preference-'.$cartId,
            balanceRequest: new BalanceCheckoutRequest($preference, 15_000, 2_000_000));
        Http::assertNothingSent();

        return (string) $order->id;
    }

    private function fundIdr(Customer $customer, int $amount): void
    {
        app(StoreBalanceLedger::class)->creditTopUp($customer, $amount, 'fixture-idr-'.$customer->id, 'fixture-idr-'.$customer->id);
    }

    private function customer(int $funds = 5_000_000): Customer
    {
        $customer = Customer::factory()->create();
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits($funds), 'fixture-'.$customer->id, 'receipt-'.$customer->id, 'v1'), 'fixture-'.$customer->id);

        return $customer;
    }

    private function product(int $stock): Product
    {
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 2_000_000, 'stock' => $stock, 'reserved_stock' => 0]);
        for ($i = 0; $i < $stock; $i++) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'race-secret-'.$i]);
        }

        return $product;
    }

    private function cart(Customer $customer, Product $product): Cart
    {
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        return $cart;
    }

    private function checkout(string $cartId, string $key): string
    {
        $order = app(OrderCheckoutService::class)->checkout(Cart::findOrFail($cartId), CheckoutPaymentIntents::usdtBalance(), $key);
        Http::assertNothingSent();

        return (string) $order->id;
    }

    private function assertOneWinner(array $results, string $failure): void
    {
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        $errors = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
        $this->assertCount(1, $errors, json_encode($results));
        $this->assertSame($failure, $errors[0]['class']);
    }
}
