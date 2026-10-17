<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerApiReadController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiPrincipal;
use App\Services\Pricing\ProductQuantityPricing;
use App\Services\Pricing\ResellerPricingContext;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CustomerApiReadTest extends TestCase
{
    use RefreshDatabase;

    private CustomerApiPrincipal $principal;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $customer = Customer::factory()->create();
        $credentials = app(CustomerApiCredentials::class);
        $this->principal = $credentials->authenticate('Bearer '.$credentials->generate($customer)->takeSecret());
    }

    public function test_visible_catalog_and_stock_match_canonical_scopes_without_private_metadata(): void
    {
        $category = Category::factory()->create(['is_active' => false]);
        Product::factory()->create(['category_id' => $category->id]);
        Product::factory()->create(['is_active' => false]);
        Product::factory()->create(['stock' => 0]);
        Product::factory()->create(['stock' => 3]);
        $supplier = Product::factory()->create(['stock' => 0]);
        DB::table('products')->where('id', $supplier->id)->update(['supplier_available_stock' => 7, 'supplier_available_until' => now()->addMinutes(5)]);
        Product::factory()->create()->delete();
        $all = $this->read('products')['data'];
        $this->assertSame(Product::publicVisible()->orderBy('id')->pluck('id')->map(strval(...))->all(), array_column($all, 'id'));
        $available = $this->read('products', ['available' => 'true'])['data'];
        $this->assertSame(Product::publicVisible()->availableForPurchase()->orderBy('id')->pluck('id')->map(strval(...))->all(), array_column($available, 'id'));
        foreach ($all as $row) {
            $product = Product::withAvailability()->findOrFail($row['id']);
            $this->assertSame($product->availableStock(), $row['stock']['available']);
            foreach (['supplier_available_stock', 'supplierSources', 'cost', 'telegram_content', 'credentials', 'reseller_price_amount'] as $private) {
                $this->assertArrayNotHasKey($private, $row);
            }
        }
        $this->assertSame('0', (string) $this->read('products', ['available' => 'false'])['data'][0]['stock']['available']);
    }

    public function test_catalog_distinguishes_legacy_stock_from_exhausted_credential_inventory_without_decryption(): void
    {
        $legacy = Product::factory()->create(['stock' => 3, 'reserved_stock' => 0]);
        $managed = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);
        $exhausted = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0]);
        foreach ([$managed, $managed] as $product) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'synthetic-available']);
        }
        foreach ([$managed, $exhausted] as $product) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => 'synthetic-retired', 'retired_at' => now()]);
        }
        DB::table('product_stock_items')->update(['secret' => 'invalid ciphertext']);

        $rows = $this->read('products', ['quantity' => '3'])['data'];
        $stock = array_column($rows, 'stock', 'id');
        $this->assertSame(['available' => 3, 'is_available' => true, 'quantity_available' => true], $stock[$legacy->id]);
        $this->assertSame(['available' => 2, 'is_available' => true, 'quantity_available' => false], $stock[$managed->id]);
        $this->assertSame(['available' => 0, 'is_available' => false, 'quantity_available' => false], $stock[$exhausted->id]);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_quotes_use_canonical_quantity_and_per_currency_override_precedence_exactly(): void
    {
        foreach ([[null, null], [8000, null], [null, 765432], [8000, 765432]] as [$idr, $usd]) {
            $product = Product::factory()->create(['price_amount' => 15000, 'usdt_price_amount' => 1234567,
                'reseller_price_amount' => $idr, 'reseller_usdt_price_amount' => $usd]);
            $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 10000, 'usdt_price_amount' => 1000001]);
            foreach ([1, 2, 20, 100] as $quantity) {
                $expected = app(ProductQuantityPricing::class)->quote($product, $quantity, ResellerPricingContext::forAuthenticatedApiRequest($this->principal->customerId));
                $row = $this->read('product', ['quantity' => (string) $quantity], (string) $product->id)['data'];
                $this->assertSame((string) $expected->idrSubtotal, $row['quotes'][0]['total']);
                $this->assertSame((string) $expected->baseIdrSubtotal, $row['quotes'][0]['regular_total']);
                $this->assertSame(UsdtMicroAmount::formatUnits($expected->usdtSubtotal), $row['quotes'][1]['total']);
                $this->assertSame(UsdtMicroAmount::formatUnits($expected->effectiveUsdtUnitAmount), $row['quotes'][1]['effective_unit']);
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
    }

    public function test_zero_base_usd_and_reseller_override_are_not_usd_eligible_anywhere(): void
    {
        // Direction B (#638): a zero base dollar price that somehow carries an
        // override. The model guard rejects it on write, so the row is staged the
        // way legacy data exists: it must still never look dollar eligible.
        $product = Product::factory()->create(['price_amount' => 15_000, 'usdt_price_amount' => 0]);
        DB::table('products')->where('id', $product->id)->update(['reseller_usdt_price_amount' => 400_000]);

        $row = $this->read('product', [], (string) $product->id)['data'];
        $this->assertSame([
            'currency' => 'IDR', 'eligible' => true, 'regular_unit' => '15000', 'effective_unit' => '15000',
            'regular_total' => '15000', 'total' => '15000',
        ], $row['quotes'][0]);
        $this->assertSame([
            'currency' => 'USD', 'eligible' => false, 'regular_unit' => '0', 'effective_unit' => '0',
            'regular_total' => '0', 'total' => '0',
        ], $row['quotes'][1]);
        $this->assertStringNotContainsString('400000', json_encode($row['quotes'][1], JSON_THROW_ON_ERROR));
        $this->failure('product', ['currency' => 'USD'], (string) $product->id, 422, 'currency_unavailable');

        $page = $this->read('products', ['quantity' => '1']);
        $listed = collect($page['data'])->firstWhere('id', (string) $product->id);
        $this->assertNotNull($listed);
        $this->assertSame($row['quotes'], $listed['quotes']);
    }

    public function test_missing_currency_hidden_products_overflow_and_invalid_inputs_fail_without_side_effects(): void
    {
        $product = Product::factory()->create();
        $row = $this->read('product', [], (string) $product->id)['data'];
        $this->assertSame(['currency' => 'USD', 'eligible' => false, 'regular_unit' => null, 'effective_unit' => null, 'regular_total' => null, 'total' => null], $row['quotes'][1]);
        $this->failure('product', ['currency' => 'USD'], (string) $product->id, 422, 'currency_unavailable');
        $hidden = Product::factory()->create(['is_active' => false]);
        foreach ([(string) $hidden->id, '9999999'] as $id) {
            $this->failure('product', [], $id, 404, 'not_found');
        }
        foreach (['0', '-1', '01', '1e2', '9223372036854775808', 'abc'] as $id) {
            $this->failure('product', [], $id, 422, 'invalid_input');
        }
        foreach ([['quantity' => '101'], ['quantity' => '0'], ['quantity' => '1.5'], ['quantity' => ['1']], ['limit' => '101'], ['cursor' => '-1'], ['category_id' => '1e2'], ['available' => 'yes'], ['secret' => 'x']] as $query) {
            $this->failure('products', $query, null, 422, 'invalid_input');
        }
        $product->update(['price_amount' => PHP_INT_MAX]);
        $this->failure('product', ['quantity' => '2'], (string) $product->id, 422, 'quote_unavailable');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('customer_balance_accounts', 0);
    }

    public static function unquotablePrices(): array
    {
        return [
            'retail IDR overflow' => [['price_amount' => PHP_INT_MAX]],
            'retail USD overflow' => [['usdt_price_amount' => PHP_INT_MAX]],
            'reseller IDR overflow' => [['reseller_price_amount' => PHP_INT_MAX]],
            'reseller USD overflow' => [['reseller_usdt_price_amount' => PHP_INT_MAX]],
            'invalid tier schedule' => [[]],
        ];
    }

    #[DataProvider('unquotablePrices')]
    public function test_unquotable_products_do_not_poison_catalog_pages_or_cursors(array $prices): void
    {
        $healthy = Product::factory()->create(['price_amount' => 15000, 'reseller_price_amount' => 8000]);
        // A zero base price is not writable together with an override, so that
        // combination reaches the read surfaces only as staged legacy data.
        $legacyZeroBaseOverride = ($prices['usdt_price_amount'] ?? null) === 0;
        $broken = Product::factory()->create($legacyZeroBaseOverride
            ? ['price_amount' => 15000, 'usdt_price_amount' => 0]
            : array_replace(['price_amount' => 15000, 'usdt_price_amount' => 2000000], $prices));
        if ($legacyZeroBaseOverride) {
            DB::table('products')->where('id', $broken->id)->update(['reseller_usdt_price_amount' => 400_000]);
        }
        if ($prices === []) {
            $broken->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 20000, 'usdt_price_amount' => 1000000]);
        }
        $last = Product::factory()->create(['price_amount' => 20000]);
        ProductStockItem::create(['product_id' => $broken->id, 'secret' => 'must-not-be-exposed']);
        DB::table('product_stock_items')->update(['secret' => 'invalid ciphertext']);

        $page = $this->read('products', ['limit' => '2', 'quantity' => '2']);
        $this->assertSame([(string) $healthy->id, (string) $broken->id], array_column($page['data'], 'id'));
        $this->assertSame('16000', $page['data'][0]['quotes'][0]['total']);
        $this->assertSame(['next_cursor' => (string) $broken->id, 'has_more' => true], $page['pagination']);
        if ($legacyZeroBaseOverride) {
            // The malformed row is refused per currency: IDR still quotes, while
            // the zero base price leaves the dollar quote unusable.
            $this->assertSame([
                'currency' => 'IDR', 'eligible' => true, 'regular_unit' => '15000', 'effective_unit' => '15000',
                'regular_total' => '30000', 'total' => '30000',
            ], $page['data'][1]['quotes'][0]);
            $this->assertSame([
                'currency' => 'USD', 'eligible' => false, 'regular_unit' => '0', 'effective_unit' => '0',
                'regular_total' => '0', 'total' => '0',
            ], $page['data'][1]['quotes'][1]);
        } else {
            foreach ($page['data'][1]['quotes'] as $index => $quote) {
                $this->assertSame([
                    'currency' => $index === 0 ? 'IDR' : 'USD', 'eligible' => false,
                    'regular_unit' => null, 'effective_unit' => null, 'regular_total' => null, 'total' => null,
                ], $quote);
            }
        }
        $this->assertSame(array_keys($page['data'][0]), array_keys($page['data'][1]));
        $this->assertStringNotContainsString('must-not-be-exposed', json_encode($page));
        $this->assertStringNotContainsString('ciphertext', json_encode($page));
        $next = $this->read('products', ['limit' => '2', 'quantity' => '2', 'cursor' => $page['pagination']['next_cursor']]);
        $this->assertSame([(string) $last->id], array_column($next['data'], 'id'));
        $this->assertSame(['next_cursor' => null, 'has_more' => false], $next['pagination']);
        $this->failure('product', ['quantity' => '2'], (string) $broken->id, 422, 'quote_unavailable');
    }

    public function test_cursor_pagination_is_stable_and_family_metadata_and_icons_are_batched(): void
    {
        $family = ProductFamily::factory()->create(['icon' => '📦']);
        $category = Category::factory()->create();
        $products = Product::factory()->count(100)->create(['product_family_id' => $family->id, 'category_id' => $category->id]);
        foreach ($products as $index => $product) {
            if ($index % 2 === 0) {
                $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 9000]);
                ProductStockItem::create(['product_id' => $product->id, 'secret' => 'must-not-be-hydrated']);
            } else {
                DB::table('products')->where('id', $product->id)->update(['stock' => 0,
                    'supplier_available_stock' => 7, 'supplier_available_until' => now()->addMinutes(5)]);
            }
        }
        $counts = [];
        foreach ([1, 20, 100] as $limit) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $rows = $this->read('products', ['limit' => (string) $limit])['data'];
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($queries);
            $this->assertCount($limit, $rows);
            foreach ($rows as $row) {
                $this->assertSame('📦', $row['icon']);
                $this->assertSame((string) $family->id, $row['family']['id']);
            }
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('"credentials"', $query['query']);
                $this->assertStringNotContainsString('supplier_product_sources', $query['query']);
            }
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertSame($counts[1], $counts[2]);
        $first = $this->read('products', ['limit' => '20']);
        $next = $this->read('products', ['limit' => '100', 'cursor' => $first['pagination']['next_cursor']]);
        $this->assertSame($products->pluck('id')->map(strval(...))->all(), array_merge(array_column($first['data'], 'id'), array_column($next['data'], 'id')));
        $this->assertFalse($next['pagination']['has_more']);
        $this->assertNull($next['pagination']['next_cursor']);
    }

    public function test_own_balances_are_fresh_and_drift_or_freeze_never_becomes_zero(): void
    {
        $customer = Customer::findOrFail($this->principal->customerId);
        app(StoreBalanceLedger::class)->creditTopUp($customer, 12345, 's05-own', 's05-own');
        $other = Customer::factory()->create();
        app(StoreBalanceLedger::class)->creditTopUp($other, 98765, 's05-other', 's05-other');
        $this->assertSame('12345', $this->read('balance')['data']['wallets'][0]['amount']);
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($customer,
            new VerifiedUsdtFunding($customer->id, UsdtMicroAmount::fromUnits(1234567), 's05-funding', 's05-receipt', 'v1'), 's05-funding');
        $this->assertSame('1.234567', $this->read('balance')['data']['wallets'][1]['amount']);
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $customer->id)->update(['balance_micro' => 1]);
        $this->assertNull($this->read('balance')['data']['wallets'][1]['amount']);
        $credentials = app(CustomerApiCredentials::class);
        $this->principal = $credentials->authenticate('Bearer '.$credentials->generate($other)->takeSecret());
        $this->assertSame('98765', $this->read('balance')['data']['wallets'][0]['amount']);
        DB::table('customer_balance_accounts')->where('customer_id', $other->id)->update(['balance' => 1]);
        $wallet = $this->read('balance')['data']['wallets'][0];
        $this->assertFalse($wallet['available']);
        $this->assertNull($wallet['amount']);
        DB::table('customer_usdt_balance_accounts')->insert(['customer_id' => $other->id, 'currency' => 'USDT', 'balance_micro' => 0, 'version' => 0, 'frozen_reason' => 'private-incident']);
        $wallet = $this->read('balance')['data']['wallets'][1];
        $this->assertTrue($wallet['frozen']);
        $this->assertFalse($wallet['available']);
        $this->assertNull($wallet['amount']);
        DB::table('customer_usdt_balance_accounts')->where('customer_id', $other->id)->update(['balance_micro' => 1]);
        $wallet = $this->read('balance')['data']['wallets'][1];
        $this->assertTrue($wallet['frozen']);
        $this->assertFalse($wallet['available']);
        $this->assertNull($wallet['amount']);
        $this->failure('balance', ['customer_id' => (string) $customer->id], null, 422, 'invalid_input');
    }

    private function read(string $action, array $query = [], ?string $id = null): array
    {
        $request = Request::create('/api/v1/read', 'GET', $query);
        $request->attributes->set('customer_api_principal', $this->principal);
        $arguments = ['request' => $request];
        if ($id !== null) {
            $arguments['id'] = $id;
        }

        return app()->call([app(CustomerApiReadController::class), $action], $arguments)->getData(true);
    }

    private function failure(string $action, array $query, ?string $id, int $status, string $code): void
    {
        try {
            $this->read($action, $query, $id);
            $this->fail('An unsafe request was accepted.');
        } catch (CustomerApiFailure $failure) {
            $this->assertSame($status, $failure->status);
            $this->assertSame($code, $failure->machineCode);
        }
    }
}
