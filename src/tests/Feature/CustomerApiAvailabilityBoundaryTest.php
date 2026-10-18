<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerApiCredential;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\CustomerApi\CustomerApiAvailability;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtBalance\VerifiedUsdtFunding;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class CustomerApiAvailabilityBoundaryTest extends TestCase
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
        $store->shouldReceive('getPrefix')->andReturn('availability:');
        Cache::extend('availability', fn () => new Repository($store));
        config(['cache.stores.availability' => ['driver' => 'availability'], 'cache.limiter' => 'availability']);
        $this->customer = Customer::factory()->create();
        $this->secret = app(CustomerApiCredentials::class)->generate($this->customer)->takeSecret();
        $this->enableCustomerApi();
    }

    public function test_off_is_the_first_answer_for_every_matched_shape_and_has_no_side_effects(): void
    {
        $this->setEnabled(false);
        $before = $this->economicCounts();

        $requests = [
            ['GET', '/api/v1', null],
            ['GET', '/api/v1/unknown/path', null],
            ['PUT', '/api/v1/balance', json_encode(['probe' => true], JSON_THROW_ON_ERROR)],
            ['GET', '/api%2Fv1/balance', null],
            ['GET', '/api/v1/%C3%28', null],
            ['POST', '/api/v1/orders', str_repeat('x', 40_000)],
        ];

        foreach ($requests as [$method, $path, $body]) {
            $response = $this->call($method, $path, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'https://browser.example.test',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->secret,
            ], $body);

            $response->assertStatus(503)
                ->assertHeader('Retry-After', '60')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeaderMissing('Access-Control-Allow-Origin')
                ->assertJsonPath('error.code', 'api_maintenance')
                ->assertJsonPath('error.request_id', $response->headers->get('X-Request-ID'))
                ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

            $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-ID')));
            $this->assertSame(['code', 'message', 'request_id'], array_keys($response->json('error')));
            $this->assertStringNotContainsString('SQL', (string) $response->getContent());
            $this->assertStringNotContainsString('capi_', (string) $response->getContent());
        }

        $this->assertSame($before, $this->economicCounts());
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$this->secret));
    }

    public function test_off_does_not_read_a_body_or_create_an_order_claim(): void
    {
        $this->setEnabled(false);
        $product = Product::factory()->create([
            'price_amount' => 15_000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'off-secret']);
        $body = [
            'product_id' => (string) $product->id,
            'quantity' => 1,
            'preference' => 'usd_only',
            'expected_totals' => ['USD' => '2'],
        ];
        $before = $this->economicCounts();

        $this->postJson('/api/v1/orders', $body, $this->headers() + ['Idempotency-Key' => 'off-order'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'api_maintenance');

        $this->assertSame($before, $this->economicCounts());
        $this->assertGreaterThan(0, $product->fresh()->stock);
        $this->assertSame(0, DB::table('customer_api_order_claims')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('store_balance_entries')->count());
        $this->assertSame(0, DB::table('customer_usdt_balance_entries')->count());
        $this->assertSame(0, DB::table('product_stock_items')->whereNotNull('order_item_id')->count());
    }

    public function test_a_database_read_failure_uses_temporarily_unavailable_not_maintenance(): void
    {
        Schema::rename('customer_api_settings', 'customer_api_settings_unreadable');

        try {
            $this->get('/api/v1/balance', $this->headers())
                ->assertStatus(503)
                ->assertHeader('Retry-After', '5')
                ->assertJsonPath('error.code', 'temporarily_unavailable');
        } finally {
            Schema::rename('customer_api_settings_unreadable', 'customer_api_settings');
        }
    }

    public function test_each_request_reads_the_singleton_exactly_once(): void
    {
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'customer_api_settings')) {
                $reads++;
            }
        });

        foreach ([false, true] as $enabled) {
            $this->setEnabled($enabled);
            $reads = 0;

            $response = $this->get('/api/v1/balance', $this->headers());

            if ($enabled) {
                $response->assertOk();
            } else {
                $response->assertStatus(503)->assertJsonPath('error.code', 'api_maintenance');
            }

            $this->assertSame(1, $reads);
        }
    }

    public function test_off_then_on_preserves_a_credential_and_replays_the_same_order(): void
    {
        [$product, $body] = $this->purchasableProduct();
        $created = $this->postJson('/api/v1/orders', $body, $this->headers() + ['Idempotency-Key' => 'availability-replay']);
        $created->assertStatus(201);
        $orderId = $created->json('data.id');
        $this->assertIsString($orderId);

        $balance = app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $availability = app(CustomerApiAvailability::class);
        $availability->change(false, 0, $owner, '203.0.113.10');

        $this->postJson('/api/v1/orders', $body, $this->headers() + ['Idempotency-Key' => 'availability-replay'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'api_maintenance');

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('customer_usdt_balance_entries')->where('kind', 'purchase_debit')->count());
        $this->assertSame($balance, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertNotNull(app(CustomerApiCredentials::class)->authenticate('Bearer '.$this->secret));

        $availability->change(true, 1, $owner, '203.0.113.10');

        $this->postJson('/api/v1/orders', $body, $this->headers() + ['Idempotency-Key' => 'availability-replay'])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('customer_api_order_claims')->count());
        $this->assertSame($balance, app(CustomerUsdtBalanceLedger::class)->snapshot($this->customer)->balanceMicro());
        $this->assertSame(1, CustomerApiCredential::query()->whereNull('revoked_at')->count());
        $this->assertSame(1, DB::table('order_items')->where('product_id', $product->id)->count());
    }

    private function setEnabled(bool $enabled): void
    {
        DB::table('customer_api_settings')->where('singleton_key', 'customer_api')
            ->update(['enabled' => $enabled]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->secret];
    }

    /** @return array{0: Product, 1: array<string, mixed>} */
    private function purchasableProduct(): array
    {
        app(CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $this->customer,
            new VerifiedUsdtFunding($this->customer->id, UsdtMicroAmount::fromUnits(5_000_000), 'availability-funding', 'availability-receipt', 'v1'),
            'availability-funding',
        );
        $product = Product::factory()->create([
            'price_amount' => 15_000,
            'usdt_price_amount' => 2_000_000,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'availability-secret']);

        return [$product, [
            'product_id' => (string) $product->id,
            'quantity' => 1,
            'preference' => 'usd_only',
            'expected_totals' => ['USD' => '2'],
        ]];
    }

    /** @return array<string, int> */
    private function economicCounts(): array
    {
        $counts = [];
        foreach ([
            'customer_api_credentials', 'customer_api_order_claims', 'orders', 'payments',
            'store_balance_entries', 'customer_usdt_balance_entries', 'product_stock_items',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
