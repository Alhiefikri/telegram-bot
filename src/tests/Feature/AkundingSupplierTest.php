<?php

namespace Tests\Feature;

use App\Enums\SupplierProvider;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AkundingSupplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Http::preventStrayRequests();
    }

    public function test_catalog_uses_bearer_auth_exact_wallet_balance_and_bounded_bulk_tiers(): void
    {
        $base = SupplierProvider::Akunding->baseUrl();
        $requests = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) use ($base, &$requests) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-akunding-key-not-live'));
            $this->assertFalse($request->hasHeader('X-API-Key'));
            $requests[] = $request->url();

            return match ($request->url()) {
                $base.'/v1/me' => Http::response([
                    'user_id' => 765,
                    'balance' => 1.23,
                    'referral_credit' => 0.1234,
                    'paid_balance' => 1.1111,
                    'api_key_prefix' => 'ak_example-prefix',
                ]),
                $base.'/v1/products?include_out_of_stock=true' => Http::response([
                    [
                        'id' => 41,
                        'name' => 'Account 30 days',
                        'description' => 'Private supplier description',
                        'features' => 'One account',
                        'unit_label' => 'account',
                        'base_price' => 12,
                        'your_price' => 10,
                        'has_special_price' => false,
                        'stock' => 20,
                        'available' => true,
                        'bulk_tiers' => [
                            ['min_qty' => 5, 'unit_price' => 9, 'your_unit_price' => 8.5],
                            ['min_qty' => 10, 'unit_price' => 8, 'your_unit_price' => 7.5],
                        ],
                    ],
                    [
                        'id' => 42,
                        'name' => 'Sold out account',
                        'description' => '',
                        'features' => '',
                        'unit_label' => 'account',
                        'base_price' => 12,
                        'your_price' => 10,
                        'has_special_price' => false,
                        'stock' => 0,
                        'available' => false,
                        'bulk_tiers' => [],
                    ],
                ]),
                default => Http::response([], 500),
            };
        });

        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());

        $this->assertSame(1_234_500, $snapshot->balanceMicros);
        $this->assertSame('USDT', $snapshot->currency);
        $this->assertSame(['account_id' => '765'], $snapshot->accountMetadata);
        $this->assertSame([$base.'/v1/me', $base.'/v1/products?include_out_of_stock=true'], $requests);
        $this->assertSame('41', $snapshot->products[0]->externalId);
        $this->assertSame(10_000_000, $snapshot->products[0]->unitPriceMicros);
        $this->assertSame([
            ['min' => 5, 'max' => 9, 'unit_price_micros' => 8_500_000],
            ['min' => 10, 'max' => null, 'unit_price_micros' => 7_500_000],
        ], $snapshot->products[0]->priceTiers);
        $this->assertTrue($snapshot->products[0]->orderable);
        $this->assertSame(0, $snapshot->products[1]->stock);
        $this->assertFalse($snapshot->products[1]->orderable);
    }

    public function test_purchase_reuses_idempotency_reference_and_recovers_paid_order_by_exact_id(): void
    {
        $supplier = $this->supplier();
        $base = SupplierProvider::Akunding->baseUrl();
        $urls = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, array $options) use ($base, &$urls) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-akunding-key-not-live'));
            $this->assertFalse($request->hasHeader('X-API-Key'));
            $urls[] = $request->url();

            if ($request->method() === 'POST') {
                $this->assertTrue($request->hasHeader('X-Idempotency-Key', 'stable-reference-955'));
                $this->assertEquals(60, $options['timeout']);
                $this->assertSame(['product_id' => 41, 'quantity' => 2], $request->data());

                return Http::response([
                    'id' => 955,
                    'status' => 'paid',
                    'product' => ['id' => 41],
                    'quantity' => 2,
                    'unit_price' => '8.5000',
                    'subtotal' => '17.0000',
                    'discount' => '0.0000',
                    'payable' => '17.0000',
                    'payment_method' => 'wallet',
                    'items' => [],
                    'charged' => '17.0000',
                    'idempotent' => false,
                ]);
            }

            $this->assertSame('GET', $request->method());

            return Http::response([
                'id' => 955,
                'status' => 'delivered',
                'product' => ['id' => 41],
                'quantity' => 2,
                'unit_price' => '8.5000',
                'subtotal' => '17.0000',
                'discount' => '0.0000',
                'payable' => '17.0000',
                'payment_method' => 'wallet',
                'items' => ['AK-DELIVERY-ONE', 'AK-DELIVERY-TWO'],
            ]);
        });

        $client = app(SupplierClient::class);
        $purchase = $client->purchase($supplier, $this->catalogItem($supplier), 2, 'stable-reference-955');
        $recovered = $client->order($supplier, '955', '41', 'stable-reference-955');

        $this->assertSame([$base.'/v1/orders', $base.'/v1/orders/955'], $urls);
        $this->assertSame('pending', $purchase->outcome);
        $this->assertTrue($purchase->identityTrusted);
        $this->assertSame(17_000_000, $purchase->costMicros);
        $this->assertSame([], $purchase->deliveries);
        $this->assertSame('delivered', $recovered->outcome);
        $this->assertTrue($recovered->deliveryTrusted);
        $this->assertSame(17_000_000, $recovered->costMicros);
        $this->assertSame(['AK-DELIVERY-ONE', 'AK-DELIVERY-TWO'], $recovered->deliveries);
    }

    public function test_order_limit_and_unknown_conflicts_never_authorize_a_new_purchase(): void
    {
        $supplier = $this->supplier();
        $client = app(SupplierClient::class);
        Http::assertNothingSent();

        $oversized = $client->purchase($supplier, $this->catalogItem($supplier), 1001, 'oversized-reference');
        $this->assertSame('rejected', $oversized->outcome);
        $this->assertSame('unsupported_product', $oversized->errorCode);
        Http::assertNothingSent();

        $requests = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            return Http::response(['detail' => 'unrecognized conflict'], 409);
        });
        $conflict = $client->purchase($supplier, $this->catalogItem($supplier), 1, 'conflict-reference');

        $this->assertSame(1, $requests);
        $this->assertSame('ambiguous', $conflict->outcome);
        $this->assertSame('conflict', $conflict->errorCode);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['detail' => 'Only 13 item(s) available'], 409));
        $outOfStock = $client->purchase($supplier, $this->catalogItem($supplier), 1, 'stock-reference');
        $this->assertSame('rejected', $outOfStock->outcome);
        $this->assertSame('conflict', $outOfStock->errorCode);
    }

    public function test_unpaid_draft_is_a_no_purchase_but_provider_refund_stays_held(): void
    {
        $supplier = $this->supplier();
        $client = app(SupplierClient::class);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'id' => 956,
            'status' => 'draft',
            'product' => ['id' => 41],
            'quantity' => 1,
            'items' => [],
            'idempotent' => false,
        ]));

        $draft = $client->purchase($supplier, $this->catalogItem($supplier), 1, 'draft-reference');

        $this->assertSame('rejected', $draft->outcome);
        $this->assertNull($draft->orderId);
        $this->assertNull($draft->costMicros);
        $this->assertSame([], $draft->deliveries);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'id' => 957,
            'status' => 'failed',
            'product' => ['id' => 41],
            'quantity' => 1,
            'payment_method' => 'wallet',
            'items' => [],
            'charged' => '8.5000',
        ]));
        $failed = $client->purchase($supplier, $this->catalogItem($supplier), 1, 'failed-reference');

        $this->assertSame('ambiguous', $failed->outcome);
        $this->assertSame('957', $failed->orderId);
        $this->assertSame('provider_refunded', $failed->errorCode);
        $this->assertTrue($failed->identityTrusted);
        $this->assertNull($failed->costMicros);
        $this->assertSame([], $failed->deliveries);
    }

    public function test_delivery_from_an_unmatched_product_is_not_trusted_or_returned(): void
    {
        $supplier = $this->supplier();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'id' => 958,
            'status' => 'delivered',
            'product' => ['id' => 42],
            'quantity' => 1,
            'payable' => '8.5000',
            'payment_method' => 'wallet',
            'items' => ['UNRELATED-CREDENTIAL'],
            'charged' => '8.5000',
        ]));

        $result = app(SupplierClient::class)->purchase($supplier, $this->catalogItem($supplier), 1, 'mismatch-reference');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertFalse($result->identityTrusted);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertSame([], $result->deliveries);
    }

    public function test_api_key_echo_from_provider_is_rejected_without_disclosing_the_key(): void
    {
        $supplier = $this->supplier();
        $key = 'fixture-akunding-key-not-live';
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'user_id' => 765,
            'balance' => 1,
            'referral_credit' => 0,
            'paid_balance' => 1,
            'api_key_prefix' => $key,
        ]));

        try {
            app(SupplierClient::class)->snapshot($supplier);
            $this->fail('A provider payload echoing the API key was accepted.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('invalid_payload', $exception->errorCode);
            $this->assertStringNotContainsString($key, $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    private function catalogItem(Supplier $supplier): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill([
            'id' => 941,
            'supplier_id' => $supplier->id,
            'external_id' => '41',
            'currency' => 'USDT',
            'orderable' => true,
            'requires_input' => false,
        ]);
    }

    private function supplier(): Supplier
    {
        return (new Supplier)->forceFill([
            'id' => 765,
            'name' => 'Private Akunding test connection',
            'provider' => 'akunding',
            'api_key' => 'fixture-akunding-key-not-live',
            'currency' => 'USDT',
            'currency_confirmed' => true,
            'is_active' => true,
        ]);
    }
}
