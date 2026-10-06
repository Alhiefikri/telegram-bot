<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentStatus;
use App\Enums\UsdtBalanceEntryKind;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InsufficientUsdtBalance;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerUsdtBalanceEntry;
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
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
