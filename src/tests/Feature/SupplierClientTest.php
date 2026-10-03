<?php

namespace Tests\Feature;

use App\Enums\SupplierProvider;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierUnavailable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Http::preventStrayRequests();
    }

    private function fake(?callable $callback = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function supplier(string $provider): Supplier
    {
        return (new Supplier)->forceFill([
            'id' => 123, 'name' => 'Private test supplier', 'provider' => $provider,
            'api_key' => 'fixture-key-not-live', 'currency' => $provider === 'prodseller' ? 'USDT' : 'USD',
            'currency_confirmed' => true, 'is_active' => true,
        ]);
    }

    private function item(Supplier $supplier, array $overrides = []): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill(array_replace([
            'id' => 77, 'supplier_id' => $supplier->id, 'external_id' => 'sku-1',
            'currency' => $supplier->currency, 'orderable' => true, 'requires_input' => false,
        ], $overrides));
    }

    private static function fixture(string $provider): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Unit/Suppliers/fixtures/'.$provider.'.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_csxstore_completed_purchase_and_exact_order_read_are_trusted(): void
    {
        $supplier = $this->supplier('csxstore');
        $this->fake(fn () => Http::response([
            'success' => true, 'status' => 'completed', 'order_id' => 'cs-test-order',
            'service_id' => 'sku-1', 'quantity' => 1, 'total_cost' => '0.01',
            'products' => ['synthetic-account-unit'],
        ]));
        $client = app(SupplierClient::class);
        foreach ([$client->purchase($supplier, $this->item($supplier), 1, 'checkout-1'), $client->order($supplier, 'cs-test-order', 'sku-1')] as $result) {
            $this->assertSame('delivered', $result->outcome);
            $this->assertTrue($result->identityTrusted);
            $this->assertTrue($result->deliveryTrusted);
            $this->assertSame(10000, $result->costMicros);
        }
    }

    public function test_csxstore_completed_without_success_or_matching_identity_does_not_authorize_delivery(): void
    {
        $supplier = $this->supplier('csxstore');
        foreach ([['success' => false], ['success' => null], ['service_id' => 'wrong-sku'], ['order_id' => 'wrong-order']] as $override) {
            $this->fake(fn () => Http::response(array_replace([
                'success' => true, 'status' => 'completed', 'order_id' => 'cs-test-order',
                'service_id' => 'sku-1', 'quantity' => 1, 'total_cost' => '0.01',
                'products' => ['synthetic-account-unit'],
            ], $override)));
            $result = app(SupplierClient::class)->order($supplier, 'cs-test-order', 'sku-1');
            $this->assertSame('ambiguous', $result->outcome);
            $this->assertFalse($result->identityTrusted);
            $this->assertFalse($result->deliveryTrusted);
        }
    }

    public static function providers(): array
    {
        // BuyMail has account/partial contracts covered by BuyMailClientTest.
        return array_map(static fn ($provider) => [$provider->value], array_filter(SupplierProvider::cases(), fn ($provider) => $provider !== SupplierProvider::BuyMail));
    }

    #[DataProvider('providers')]
    public function test_catalog_purchase_and_known_order_contracts(string $provider): void
    {
        $supplier = $this->supplier($provider);
        $fixture = self::fixture($provider);
        $base = SupplierProvider::from($provider)->baseUrl();
        $purchasePath = $provider === 'prodseller' ? '/orders' : '/order';
        $this->fake(function ($request, $options) use ($base, $fixture, $provider, $purchasePath) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($request->hasHeader('X-API-Key', 'fixture-key-not-live'));
            if ($request->method() === 'POST') {
                $this->assertSame($base.$purchasePath, $request->url());
                $this->assertSame(2, $request['quantity']);
                $this->assertSame('sku-1', $request[$provider === 'prodseller' ? 'productId' : 'service_id']);
                if ($provider === 'prodseller') {
                    $this->assertTrue($request->hasHeader('Idempotency-Key', 'checkout-1'));
                } elseif ($provider === 'aivault') {
                    $this->assertSame('checkout-1', $request['external_order_id']);
                    $this->assertArrayNotHasKey('accept_normal_price', $request->data());
                } else {
                    $this->assertFalse($request->hasHeader('Idempotency-Key'));
                    $this->assertArrayNotHasKey('external_order_id', $request->data());
                }

                return Http::response($fixture['purchase'], 201);
            }

            return Http::response(match ($request->url()) {
                $base.'/me', $base.'/balance' => $fixture['account'],
                $base.'/products' => $fixture['catalog'],
                $base.'/products/sku-1' => $fixture['product_detail'],
                $base.$purchasePath.'/order-1' => $fixture['order'],
            });
        });
        $client = app(SupplierClient::class);
        $snapshot = $client->snapshot($supplier);
        $this->assertSame($fixture['balance_micros'], $snapshot->balanceMicros);
        $this->assertSame($supplier->currency, $snapshot->currency);
        $product = $snapshot->products[0];
        $this->assertSame('sku-1', $product->externalId);
        $this->assertSame($fixture['stock'], $product->stock);
        $this->assertSame($fixture['price_micros'], $product->unitPriceMicros);
        $this->assertTrue($product->orderable);
        if ($provider === 'warzone' || $provider === 'aivault') {
            $this->assertSame(450_000, $product->priceTiers[1]['unit_price_micros']);
        }
        if ($provider === 'aivault') {
            $this->assertSame([['min' => 500, 'percent_basis_points' => 500]], $product->bulkDiscounts);
        }
        $purchase = $client->purchase($supplier, $this->item($supplier), 2, 'checkout-1');
        $this->assertSame('delivered', $purchase->outcome);
        $this->assertSame('order-1', $purchase->orderId);
        $this->assertSame(2, $purchase->quantity);
        $this->assertSame($fixture['cost_micros'], $purchase->costMicros);
        $this->travel(2)->seconds();
        $known = $client->order($supplier, 'order-1');
        $this->assertSame('delivered', $known->outcome);
        $this->assertSame($purchase->deliveries, $known->deliveries);
        $this->assertSame($purchase->costMicros, $known->costMicros);
        Http::assertSentCount($provider === 'prodseller' ? 5 : 4);
    }

    #[DataProvider('providers')]
    public function test_transport_conflicts_and_unknown_server_failures_are_one_shot_ambiguous(string $provider): void
    {
        $supplier = $this->supplier($provider);
        $client = app(SupplierClient::class);
        foreach ([409, 500, 502] as $status) {
            Cache::flush();
            $this->fake(fn () => Http::response(['error' => 'unverified failure fixture-key-not-live'], $status));
            $result = $client->purchase($supplier, $this->item($supplier), 1, 'checkout-1');
            $this->assertSame('ambiguous', $result->outcome);
            $this->assertStringNotContainsString('fixture-key-not-live', json_encode($result));
            Http::assertSentCount(1);
        }
        Cache::flush();
        $this->fake(fn () => throw new ConnectionException('secret fixture-key-not-live / full response'));
        $result = $client->purchase($supplier, $this->item($supplier), 1, 'checkout-1');
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
        Http::assertSentCount(0);
    }

    public function test_documented_warzone_no_purchase_failures_allow_safe_rejection(): void
    {
        $supplier = $this->supplier('warzone');
        foreach ([400, 429, 503] as $status) {
            Cache::flush();
            $this->fake(fn () => Http::response(['error' => 'private provider diagnostic'], $status));
            $result = app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 1, 'checkout-1');
            $this->assertSame('rejected', $result->outcome);
            Http::assertSentCount(1);
        }
    }

    public function test_documented_aivault_atomic_rollback_and_buffer_conflict_are_rejected_without_override(): void
    {
        $supplier = $this->supplier('aivault');
        foreach ([[500, 'internal_error'], [409, 'Buffer Stock Conflict']] as [$status, $error]) {
            Cache::flush();
            $this->fake(fn () => Http::response(['error' => $error], $status));
            $this->assertSame('rejected', app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 1, 'checkout-1')->outcome);
            Http::assertSent(fn ($request) => ! array_key_exists('accept_normal_price', $request->data()));
            Http::assertSentCount(1);
        }
    }

    public function test_redirects_never_follow_or_produce_delivery_and_error_is_private(): void
    {
        $supplier = $this->supplier('prodseller');
        $this->fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);

            return Http::response('fixture-key-not-live secret', 302, ['Location' => 'https://untrusted.example.test']);
        });
        $client = app(SupplierClient::class);
        $this->assertSame('ambiguous', $client->purchase($supplier, $this->item($supplier), 1, 'checkout-1')->outcome);
        try {
            $client->snapshot($supplier);
            $this->fail('Redirect must fail closed.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('redirect', $exception->errorCode);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('fixture-key-not-live', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_read_budget_is_shared_across_accounts_but_retains_purchase_capacity(): void
    {
        $supplier = $this->supplier('warzone');
        $other = $this->supplier('warzone')->forceFill(['id' => 124, 'api_key' => 'another-fixture-key']);
        $fixture = self::fixture('warzone');
        $this->fake(fn ($request) => Http::response($request->method() === 'POST' ? $fixture['purchase'] : $fixture['order']));
        $client = app(SupplierClient::class);
        $this->assertSame('delivered', $client->order($supplier, 'order-1')->outcome);
        $this->assertSame('delivered', $client->order($other, 'order-1')->outcome);
        try {
            $client->order($supplier, 'order-1');
            $this->fail('Catalog/read budget must stop before consuming purchase capacity.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('rate_limited', $exception->errorCode);
        }
        $this->assertSame('delivered', $client->purchase($supplier, $this->item($supplier), 2, 'checkout-1')->outcome);
        $this->assertSame('rejected', $client->purchase($other, $this->item($other), 2, 'checkout-2')->outcome);
        Http::assertSentCount(3);
    }

    public static function unsupportedPurchases(): array
    {
        return [
            ['requires_input', true, 1, 'checkout-1'], ['orderable', false, 1, 'checkout-1'],
            ['external_id', '../escape', 1, 'checkout-1'], ['currency', 'USDT', 1, 'checkout-1'],
            ['supplier_id', 999, 1, 'checkout-1'], ['orderable', true, 0, 'checkout-1'],
            ['orderable', true, 10_001, 'checkout-1'], ['orderable', true, 1, "key\r\nInjected: true"],
            ['orderable', true, 1, str_repeat('x', 101)],
        ];
    }

    #[DataProvider('unsupportedPurchases')]
    public function test_unsupported_inputs_never_dispatch(string $field, mixed $value, int $quantity, string $reference): void
    {
        $this->fake();
        $supplier = $this->supplier('aivault');
        $result = app(SupplierClient::class)->purchase($supplier, $this->item($supplier, [$field => $value]), $quantity, $reference);
        $this->assertSame('rejected', $result->outcome);
        Http::assertNothingSent();
    }

    public static function incompleteResults(): array
    {
        return [
            ['quantity', 3], ['products', ['ONLY-ONE']], ['products', ['ONE', 'TWO', 'EXTRA']],
            ['products', [['key' => 'unsupported-object'], 'TWO']], ['products', ['', 'TWO']],
            ['products', [str_repeat('x', 16_001), 'TWO']], ['total_cost', '-1'],
            ['total_cost', '9223372036854.775808'], ['order_id', '../escape'],
            ['service_id', 'another-sku'], ['external_order_id', 'another-checkout'], ['currency', 'USDT'],
            ['quantity', null], ['total_cost', null], ['status', 'failed'],
        ];
    }

    #[DataProvider('incompleteResults')]
    public function test_partial_invalid_or_mismatched_purchase_evidence_is_never_complete(string $field, mixed $value): void
    {
        $supplier = $this->supplier('aivault');
        $payload = array_replace(self::fixture('aivault')['purchase'], [$field => $value]);
        $this->fake(fn () => Http::response($payload, 201));
        $this->assertSame('ambiguous', app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 2, 'checkout-1')->outcome);
        Http::assertSentCount(1);
    }

    public function test_partial_evidence_is_retained_and_queued_order_is_pending(): void
    {
        $supplier = $this->supplier('aivault');
        $payload = array_replace(self::fixture('aivault')['purchase'], ['products' => ['ONLY-ONE']]);
        $this->fake(fn () => Http::response($payload, 201));
        $client = app(SupplierClient::class);
        $partial = $client->purchase($supplier, $this->item($supplier), 2, 'checkout-1');
        $this->assertSame(['ONLY-ONE'], $partial->deliveries);
        $this->assertSame('order-1', $partial->orderId);
        $this->fake(fn () => Http::response(['order_id' => 'order-1', 'status' => 'queued', 'products' => []]));
        $pending = $client->order($supplier, 'order-1');
        $this->assertSame('pending', $pending->outcome);
        $this->assertNull($pending->costMicros);
        $this->assertNull($pending->quantity);
    }

    public function test_snapshot_excludes_email_manual_and_unpriced_products(): void
    {
        $supplier = $this->supplier('prodseller');
        $products = [
            ['id' => 'email', 'name' => 'Email activation', 'price' => '1', 'stock' => 2, 'requiresEmailActivation' => true],
            ['id' => 'manual', 'name' => 'Manual activation', 'price' => '1', 'stock' => null, 'delivery' => ['type' => 'custom']],
            ['id' => 'unpriced', 'name' => 'Unpriced', 'price' => null, 'stock' => 2, 'orderable' => false],
        ];
        $this->fake(fn ($request) => Http::response(str_ends_with($request->url(), '/balance') ? ['balance' => '1'] : ['products' => $products]));
        $catalog = app(SupplierClient::class)->snapshot($supplier);
        $this->assertFalse($catalog->products[0]->orderable);
        $this->assertTrue($catalog->products[0]->requiresInput);
        $this->assertFalse($catalog->products[1]->orderable);
        $this->assertTrue($catalog->products[1]->requiresInput);
        $this->assertFalse($catalog->products[2]->orderable);
    }

    public function test_currency_confirmation_and_header_controls_fail_before_network(): void
    {
        $this->fake();
        foreach ([['currency_confirmed' => false], ['api_key' => "key\r\nInjected: yes"], ['currency' => 'EUR']] as $overrides) {
            $supplier = $this->supplier('arrs')->forceFill($overrides);
            try {
                app(SupplierClient::class)->snapshot($supplier);
                $this->fail('Unsafe connection must be rejected.');
            } catch (SupplierUnavailable $exception) {
                $this->assertContains($exception->errorCode, ['currency_mismatch', 'invalid_key']);
                $this->assertNull($exception->getPrevious());
            }
        }
        Http::assertNothingSent();
    }

    public function test_client_never_runs_http_inside_a_financial_transaction(): void
    {
        $this->fake();
        DB::beginTransaction();
        try {
            $supplier = $this->supplier('aivault');
            $this->assertSame('financial_lock', app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 1, 'checkout-1')->errorCode);
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
    }

    public function test_download_limit_stops_progress_and_never_preserves_original_exception(): void
    {
        $supplier = $this->supplier('aivault');
        $this->fake(function ($request, $options) {
            $options['progress'](0, 2_097_153);

            return Http::response([]);
        });
        try {
            app(SupplierClient::class)->snapshot($supplier);
            $this->fail('Oversized transfer must stop.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('transport', $exception->errorCode);
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_bounded_json_body_and_catalog_counts_fail_closed(): void
    {
        $supplier = $this->supplier('aivault');
        foreach ([str_repeat('x', 2_097_153), '{invalid', '{"balance":1,"products":'.json_encode(array_fill(0, 501, ['service_id' => 'sku', 'name' => 'Sku', 'stock' => 1, 'price' => 1])).'}'] as $body) {
            Cache::flush();
            $this->fake(fn () => Http::response($body));
            try {
                app(SupplierClient::class)->snapshot($supplier);
                $this->fail('Unbounded or invalid payload must fail closed.');
            } catch (SupplierUnavailable $exception) {
                $this->assertSame('invalid_payload', $exception->errorCode);
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function test_json_decimal_tokens_are_exact_and_float_artifacts_round_conservatively(): void
    {
        $supplier = $this->supplier('arrs');
        $this->fake(fn ($request) => Http::response(str_ends_with($request->url(), '/me')
            ? '{"wallet_balance":0.040000000000000036}'
            : '{"services":[{"service_id":"sku-1","name":"License","price":0.580000000000000071,"stock":2}]}'));
        $snapshot = app(SupplierClient::class)->snapshot($supplier);
        $this->assertSame(40_000, $snapshot->balanceMicros);
        $this->assertSame(580_001, $snapshot->products[0]->unitPriceMicros);
        $this->travel(2)->seconds();
        $this->fake(fn () => Http::response('{"success":true,"order_id":"order-1","quantity":2,"total_cost":9007199254.740991,"products":["ONE","TWO"]}'));
        $this->assertSame(9_007_199_254_740_991, app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 2, 'checkout-1')->costMicros);
    }

    public function test_decimal_looking_delivery_and_description_strings_are_unchanged(): void
    {
        $supplier = $this->supplier('aivault');
        $description = 'Account 0.58:secret1e3 "quoted 1.2" and slash \\ 4.2';
        $catalog = ['products' => [['service_id' => 'sku-1', 'name' => 'Plan 1.2', 'description' => $description, 'price' => '0.58', 'stock' => 2]]];
        $this->fake(fn ($request) => Http::response(str_ends_with($request->url(), '/me') ? ['balance' => '100'] : $catalog));
        $this->assertSame($description, app(SupplierClient::class)->snapshot($supplier)->products[0]->description);
        $deliveries = ['https://delivery.example.test/0.58?q=1.2', 'account:pass1e3"1.2"\\0.58'];
        $payload = array_replace(self::fixture('aivault')['purchase'], ['products' => $deliveries]);
        $this->fake(fn () => Http::response($payload, 201));
        $this->assertSame($deliveries, app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 2, 'checkout-1')->deliveries);
    }

    public function test_secret_echoes_are_never_retained_in_catalog_or_delivery(): void
    {
        $supplier = $this->supplier('aivault');
        $catalog = ['products' => [['service_id' => 'sku-1', 'name' => 'Echo fixture-key-not-live', 'price' => '1', 'stock' => 2]]];
        $this->fake(fn ($request) => Http::response(str_ends_with($request->url(), '/me') ? ['balance' => '100'] : $catalog));
        try {
            app(SupplierClient::class)->snapshot($supplier);
            $this->fail('API key echo must not enter catalog storage.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('invalid_payload', $exception->errorCode);
            $this->assertStringNotContainsString('fixture-key-not-live', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $payload = array_replace(self::fixture('aivault')['purchase'], ['products' => ['fixture-key-not-live', 'OTHER']]);
        $this->fake(fn () => Http::response($payload, 201));
        $result = app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 2, 'checkout-1');
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame([], $result->deliveries);
    }

    public function test_catalog_detail_work_is_bounded_and_never_returns_partial_snapshot(): void
    {
        $supplier = $this->supplier('prodseller');
        $rows = array_map(static fn ($number) => ['id' => 'sku-'.$number, 'name' => 'License', 'price' => '1'], range(1, 101));
        $this->fake(function ($request) use ($rows) {
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['balance' => '100']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['products' => $rows]);
            }

            return Http::response(['id' => basename($request->url()), 'name' => 'License', 'price' => '1', 'stock' => 2]);
        });
        try {
            app(SupplierClient::class)->snapshot($supplier);
            $this->fail('Incomplete bounded catalog must not be applied.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('catalog_limit', $exception->errorCode);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sku-101'));
    }

    public function test_prodseller_stock_details_use_async_reads_and_keep_every_product(): void
    {
        $supplier = $this->supplier('prodseller');
        $rows = array_map(static fn ($number) => ['id' => 'sku-'.$number, 'name' => 'License', 'price' => '1'], range(1, 6));
        $synchronous = [];
        $this->fake(function ($request, $options) use ($rows, &$synchronous) {
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['balance' => '100']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['products' => $rows]);
            }
            $synchronous[] = (bool) ($options['synchronous'] ?? false);

            return Http::response(['id' => basename($request->url()), 'name' => 'License', 'price' => '1', 'stock' => 2]);
        });
        $snapshot = app(SupplierClient::class)->snapshot($supplier);
        $this->assertCount(6, $snapshot->products);
        $this->assertSame(array_fill(0, 6, false), $synchronous);
        $this->assertSame(array_column($rows, 'id'), array_map(fn ($product) => $product->externalId, $snapshot->products));
        Http::assertSentCount(8);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_a_rejected_detail_discards_the_snapshot_and_stops_before_the_next_batch(): void
    {
        $supplier = $this->supplier('prodseller');
        $rows = array_map(static fn ($number) => ['id' => 'sku-'.$number, 'name' => 'License', 'price' => '1'], range(1, 6));
        $this->fake(function ($request) use ($rows) {
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['balance' => '100']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['products' => $rows]);
            }
            if (str_ends_with($request->url(), '/sku-2')) {
                return Http::response(['error' => 'fixture-key-not-live'], 401);
            }

            return Http::response(['id' => basename($request->url()), 'name' => 'License', 'price' => '1', 'stock' => 2]);
        });
        try {
            app(SupplierClient::class)->snapshot($supplier);
            $this->fail('A rejected detail must not return a partial catalog.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('authentication', $exception->errorCode);
            $this->assertStringNotContainsString('fixture-key-not-live', $exception->getMessage());
        }
        Http::assertSentCount(7);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sku-6') || $request->method() !== 'GET');
    }

    public function test_exponent_economic_input_and_conflicting_tiers_are_rejected(): void
    {
        $supplier = $this->supplier('aivault');
        foreach ([
            '{"products":[{"service_id":"sku-1","name":"License","price":1e-3,"stock":2}]}',
            '{"products":[{"service_id":"sku-1","name":"License","stock":2,"pricing_tiers":[{"min":1,"max":10,"price":1},{"min":10,"max":20,"price":0.9}]}]}',
            '{"currency":"USDT","products":[]}',
        ] as $catalog) {
            Cache::flush();
            $this->fake(fn ($request) => Http::response(str_ends_with($request->url(), '/me') ? '{"balance":100}' : $catalog));
            try {
                app(SupplierClient::class)->snapshot($supplier);
                $this->fail('Unsupported economics must fail closed.');
            } catch (SupplierUnavailable $exception) {
                $this->assertContains($exception->errorCode, ['invalid_payload', 'currency_mismatch']);
            }
        }
    }

    public function test_known_order_recovery_survives_disabled_connection_and_nested_delivery_variants(): void
    {
        $supplier = $this->supplier('csxstore')->forceFill(['is_active' => false]);
        $this->fake(fn () => Http::response([
            'success' => true, 'order' => ['order_id' => 'order-1', 'status' => 'success'],
            'delivered_products' => ['account@example.test:password'],
        ]));
        $client = app(SupplierClient::class);
        $result = $client->order($supplier, 'order-1');
        $this->assertSame('delivered', $result->outcome);
        $this->assertSame(['account@example.test:password'], $result->deliveries);
        $this->assertNull($result->quantity);
        $this->assertNull($result->costMicros);
        $this->assertSame('inactive', $client->purchase($supplier, $this->item($supplier), 1, 'checkout-1')->errorCode);
        Http::assertSentCount(1);
    }

    public function test_wrong_known_order_and_path_dot_segments_fail_closed(): void
    {
        $supplier = $this->supplier('aivault');
        $this->fake(fn () => Http::response(['order_id' => 'another-order', 'status' => 'delivered', 'products' => ['SECRET-OTHER-ORDER']]));
        $client = app(SupplierClient::class);
        $this->assertSame('ambiguous', $client->order($supplier, 'order-1')->outcome);
        try {
            $client->order($supplier, '..');
            $this->fail('Path dot segments must not reach HTTP.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('invalid_identifier', $exception->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_content_length_limit_stops_download_before_reading_response_body(): void
    {
        $supplier = $this->supplier('aivault');
        $this->fake(function ($request, $options) {
            $options['on_headers'](new Response(200, ['Content-Length' => '2097153']));

            return Http::response([]);
        });
        $result = app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 1, 'checkout-1');
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
    }

    public static function providerSpecificFailures(): array
    {
        return [
            ['prodseller', 402, 'rejected'], ['csxstore', 402, 'ambiguous'],
            ['arrs', 401, 'ambiguous'], ['arrs', 402, 'ambiguous'],
            ['warzone', 403, 'rejected'], ['aivault', 502, 'ambiguous'],
            ['aivault', 503, 'ambiguous'],
        ];
    }

    #[DataProvider('providerSpecificFailures')]
    public function test_undocumented_failure_codes_do_not_authorize_another_purchase(string $provider, int $status, string $outcome): void
    {
        $supplier = $this->supplier($provider);
        $this->fake(fn () => Http::response(['error' => 'private diagnostic'], $status));
        $this->assertSame($outcome, app(SupplierClient::class)->purchase($supplier, $this->item($supplier), 1, 'checkout-1')->outcome);
        Http::assertSentCount(1);
    }
}
