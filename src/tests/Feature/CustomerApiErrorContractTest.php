<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiOrderCheckout;
use App\Services\CustomerApi\CustomerApiOrderRequest;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class CustomerApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $connection = Mockery::mock();
        $connection->shouldReceive('eval')->andReturn([1, 120, 119, 2000000000, 0]);
        $store = Mockery::mock(RedisStore::class)->makePartial();
        $store->shouldReceive('connection')->andReturn($connection);
        $store->shouldReceive('getPrefix')->andReturn('error-contract:');
        Cache::extend('error-contract', fn () => new Repository($store));
        config(['cache.stores.error-contract' => ['driver' => 'error-contract'], 'cache.limiter' => 'error-contract']);
        $this->customer = Customer::factory()->create();
        $this->secret = app(CustomerApiCredentials::class)->generate($this->customer)->takeSecret();
    }

    public function test_balance_rejects_stray_query_and_body_fields(): void
    {
        $this->get('/api/v1/balance?x=1', $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_input');
        $this->json('GET', '/api/v1/balance', ['x' => 1], $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_input');
    }

    public function test_unquotable_catalog_row_remains_200_but_detail_is_422(): void
    {
        $product = Product::factory()->create(['price_amount' => PHP_INT_MAX]);
        $this->get('/api/v1/products?quantity=2', $this->headers())->assertOk()
            ->assertJsonPath('data.0.quotes.0.eligible', false)->assertJsonPath('data.0.quotes.0.total', null)
            ->assertJsonPath('data.0.quotes.1.eligible', false)->assertJsonPath('data.0.quotes.1.total', null);
        $this->get('/api/v1/products/'.$product->id.'?quantity=2', $this->headers())
            ->assertStatus(422)->assertJsonPath('error.code', 'quote_unavailable');
    }

    public function test_price_changed_quotes_include_usd_only_when_available(): void
    {
        foreach ([null, 2000000] as $usd) {
            $product = Product::factory()->create(['price_amount' => 15000, 'usdt_price_amount' => $usd, 'stock' => 1]);
            $response = $this->postJson('/api/v1/orders', [
                'product_id' => (string) $product->id, 'quantity' => 1,
                'preference' => 'idr_only', 'expected_totals' => ['IDR' => '1'],
            ], $this->headers() + ['Idempotency-Key' => 'stale-'.$product->id]);
            $expected = [['currency' => 'IDR', 'total' => '15000']];
            if ($usd !== null) {
                $expected[] = ['currency' => 'USD', 'total' => '2'];
            }
            $response->assertStatus(409)->assertJsonPath('error.code', 'price_changed')
                ->assertJsonPath('error.quotes', $expected);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('store_balance_entries', 0);
        $this->assertDatabaseCount('customer_usdt_balance_entries', 0);
    }

    public function test_delivery_bounds_and_admission_outage_all_supply_retry_after(): void
    {
        $order = $this->buy();
        ProductStockItem::sole()->update(['secret' => str_repeat('x', 1048577)]);
        $this->get('/api/v1/orders/'.$order->id.'/delivery', $this->headers())
            ->assertStatus(503)->assertHeader('Retry-After', '5')
            ->assertJsonPath('error.code', 'temporarily_unavailable');
        ProductStockItem::sole()->update(['secret' => 'fixture-secret']);
        $order->orderItems()->sole()->update(['quantity' => 101]);
        $this->get('/api/v1/orders/'.$order->id.'/delivery', $this->headers())
            ->assertStatus(503)->assertHeader('Retry-After', '5')
            ->assertJsonPath('error.code', 'temporarily_unavailable');
        config(['cache.limiter' => 'array']);
        $this->get('/api/v1/balance', $this->headers())->assertStatus(503)->assertHeader('Retry-After', '5');
    }

    public function test_malformed_paths_have_safe_json_and_matching_request_ids_before_admission(): void
    {
        foreach (['/api/v1/%C3%28', '/api/v1/products/%FF', '/api%2Fv1/%C3%28'] as $path) {
            $response = $this->get($path)->assertStatus(400)
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
                ->assertJsonPath('error.code', 'invalid_path');
            $id = $response->headers->get('X-Request-ID');
            $this->assertTrue(Str::isUuid($id ?? ''));
            $response->assertJsonPath('error.request_id', $id);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertHeaderMissing('Access-Control-Allow-Origin');
            $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->secret];
    }

    private function buy(): Order
    {
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp($this->customer,
            new VerifiedUsdtFunding($this->customer->id, UsdtMicroAmount::fromUnits(5000000), 'contract-funding', 'contract-receipt', 'v1'), 'contract-funding');
        $product = Product::factory()->create(['price_amount' => 15000, 'usdt_price_amount' => 2000000, 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'fixture-secret']);
        $request = Request::create('/api/v1/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'product_id' => (string) $product->id, 'quantity' => 1, 'preference' => 'usd_only', 'expected_totals' => ['USD' => '2'],
        ], JSON_THROW_ON_ERROR));
        $request->headers->set('Idempotency-Key', 'contract-purchase');
        $principal = app(CustomerApiCredentials::class)->authenticate('Bearer '.$this->secret);

        return app(CustomerApiOrderCheckout::class)->place($principal, CustomerApiOrderRequest::fromRequest($request))->order;
    }
}
