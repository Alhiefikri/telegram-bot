<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierExchangeRate;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierPricing;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NasteleSupplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function supplier(): Supplier
    {
        return (new Supplier)->forceFill(['id' => 123, 'provider' => 'nastele', 'api_key' => 'synthetic-nastele-secret', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001', 'is_active' => true]);
    }

    private function item(): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill(['supplier_id' => 123, 'external_id' => '43', 'currency' => 'VND', 'orderable' => true, 'requires_input' => false]);
    }

    private function payload(): array
    {
        return ['success' => true, 'data' => ['orderId' => 2244, 'totalAmount' => 60000, 'items' => [['quantity' => 2, 'unitPrice' => 30000, 'accounts' => ['synthetic-a|password', 'synthetic-b|password']]]]];
    }

    public function test_snapshot_uses_two_reads_for_a_large_catalog_and_exact_wholesale_boundaries(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('X-API-Key', 'synthetic-nastele-secret'));
            $this->assertFalse($options['allow_redirects']);
            if ($request->url() === 'https://nastele.online/api/partner/balance') {
                return Http::response(['success' => true, 'data' => ['balance' => 1000000, 'currency' => 'VND']]);
            }
            $rows = [];
            for ($id = 1; $id <= 100; $id++) {
                $rows[] = ['id' => $id, 'name' => 'Private product '.$id, 'description' => '', 'price' => 30000, 'stock' => 100, 'wholesale_tiers' => [['minQuantity' => 25, 'unitPrice' => 26000], ['minQuantity' => 10, 'unitPrice' => 28000]]];
            }

            return Http::response(['success' => true, 'data' => $rows]);
        });
        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());
        $this->assertSame(1_000_000_000_000, $snapshot->balanceMicros);
        $this->assertSame('VND', $snapshot->currency);
        $sku = $this->item()->forceFill(['unit_price_micros' => $snapshot->products[0]->unitPriceMicros, 'price_tiers' => $snapshot->products[0]->priceTiers]);
        foreach ([9 => 270000, 10 => 280000, 24 => 672000, 25 => 650000] as $quantity => $cost) {
            $this->assertSame($cost * 1_000_000, app(SupplierPricing::class)->costMicros($sku, $quantity));
        }
        $this->assertSame(19501, SupplierMoney::costIdr(30_000_000_000, $this->supplier()->exchange_rate_idr));
        Http::assertSentCount(2);
    }

    public function test_snapshot_and_wholesale_tiers_convert_at_the_owner_funding_rate_with_a_final_ceiling(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/balance')) {
                return Http::response(['success' => true, 'data' => ['balance' => 1000000, 'currency' => 'VND']]);
            }

            return Http::response(['success' => true, 'data' => [[
                'id' => 43, 'name' => 'Synthetic SKU', 'price' => 69000, 'stock' => 100,
                'wholesale_tiers' => [['minQuantity' => 25, 'unitPrice' => 62000], ['minQuantity' => 10, 'unitPrice' => 66000]],
            ]]]);
        });
        $snapshot = app(SupplierClient::class)->snapshot($this->supplier()->forceFill(['exchange_rate_idr' => '0.692308']));

        $this->assertSame('VND', $snapshot->currency);
        $this->assertSame(1_000_000_000_000, $snapshot->balanceMicros);
        $product = $snapshot->products[0];
        $this->assertSame(69_000_000_000, $product->unitPriceMicros);
        $sku = $this->item()->forceFill(['unit_price_micros' => $product->unitPriceMicros, 'price_tiers' => $product->priceTiers]);
        $pricing = app(SupplierPricing::class);
        $this->assertSame(69_000_000_000, $pricing->costMicros($sku, 1));
        $this->assertSame(621_000_000_000, $pricing->costMicros($sku, 9));
        $this->assertSame(660_000_000_000, $pricing->costMicros($sku, 10));
        $this->assertSame(1_550_000_000_000, $pricing->costMicros($sku, 25));
        // 69.000 VND x 0.692308 = 47.769,252 -> Rp47.770; ceiling applies only to the final IDR amount.
        $this->assertSame(47770, SupplierMoney::costIdr($pricing->costMicros($sku, 1), '0.692308'));
        $this->assertSame(456924, SupplierMoney::costIdr($pricing->costMicros($sku, 10), '0.692308'));
        $this->assertSame(1073078, SupplierMoney::costIdr($pricing->costMicros($sku, 25), '0.692308'));
        $this->assertSame(20770, SupplierMoney::costIdr(30_000_000_000, '0.692308'));
        // The owner's conservative market estimate and the earlier synthetic rate stay exact.
        $this->assertSame(48706, SupplierMoney::costIdr(69_000_000_000, '0.705882'));
        $this->assertSame(19501, SupplierMoney::costIdr(30_000_000_000, '0.650001'));
        Http::assertSentCount(2);
    }

    public function test_a_rupiah_per_usdt_rate_is_outside_the_vnd_band(): void
    {
        $supplier = $this->supplier()->forceFill(['exchange_rate_idr' => '18000']);
        $sku = $this->item()->forceFill(['unit_price_micros' => 69_000_000_000]);

        $this->assertFalse(SupplierExchangeRate::isPlausible($supplier->currency, $supplier->exchange_rate_idr));
        $this->assertTrue(SupplierExchangeRate::isPlausible($supplier->currency, '0.692308'));
        $this->assertSame(1_242_000_000, SupplierMoney::costIdr($sku->unit_price_micros, '18000'));
        $this->assertSame(47770, SupplierMoney::costIdr($sku->unit_price_micros, '0.692308'));
    }

    public function test_successful_create_and_exact_get_deliver_only_complete_distinct_accounts(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->assertSame('https://nastele.online/api/partner/orders', $request->url());
                $this->assertSame(['productId' => 43, 'qty' => 2], $request->data());
                $this->assertFalse($request->hasHeader('Idempotency-Key'));

                return Http::response($this->payload(), 201);
            }
            $this->assertSame('https://nastele.online/api/partner/orders/2244', $request->url());
            $payload = $this->payload();
            $payload['data']['status'] = 'completed';

            return Http::response($payload);
        });
        $client = app(SupplierClient::class);
        foreach ([$client->purchase($this->supplier(), $this->item(), 2, 'original-reference'), $client->order($this->supplier(), '2244', '43')] as $result) {
            $this->assertSame('delivered', $result->outcome);
            $this->assertSame(60_000_000_000, $result->costMicros);
            $this->assertSame(['synthetic-a|password', 'synthetic-b|password'], $result->deliveries);
            $this->assertTrue($result->identityTrusted);
            $this->assertTrue($result->deliveryTrusted);
        }
    }

    public function test_incomplete_duplicate_or_disputed_create_keeps_identity_without_authorizing_delivery(): void
    {
        foreach (['missing', 'duplicate', 'quantity', 'cost', 'multiple_items', 'status'] as $case) {
            Cache::flush();
            Http::swap(new Factory);
            $payload = $this->payload();
            match ($case) {
                'missing' => $payload['data']['items'][0]['accounts'] = [],
                'duplicate' => $payload['data']['items'][0]['accounts'][1] = 'synthetic-a|password',
                'quantity' => $payload['data']['items'][0]['quantity'] = 1,
                'cost' => $payload['data']['totalAmount'] = 60001,
                'multiple_items' => $payload['data']['items'][] = $payload['data']['items'][0],
                'status' => $payload['data']['status'] = 'unknown',
            };
            Http::fake(fn () => Http::response($payload, 201));
            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'original-reference');
            $this->assertSame('ambiguous', $result->outcome, $case);
            $this->assertSame('2244', $result->orderId);
            $this->assertTrue($result->identityTrusted);
            $this->assertFalse($result->deliveryTrusted);
            if ($case === 'status') {
                $this->assertNull($result->costMicros);
                $this->assertNull($result->quantity);
            }
        }
    }

    public function test_wrong_exact_order_and_missing_get_status_cannot_authorize_delivery(): void
    {
        Http::fake(fn () => Http::response($this->payload()));
        $client = app(SupplierClient::class);
        $wrong = $client->order($this->supplier(), '9999', '43');
        $this->assertFalse($wrong->identityTrusted);
        $this->assertFalse($wrong->deliveryTrusted);
        $missing = $client->order($this->supplier(), '2244', '43');
        $this->assertTrue($missing->identityTrusted);
        $this->assertSame('ambiguous', $missing->outcome);
        $this->assertFalse($missing->deliveryTrusted);
        $this->assertNull($missing->costMicros);
        $this->assertNull($missing->quantity);
    }

    public function test_timeout_and_unknown_conflicts_are_ambiguous_but_documented_rejections_are_safe(): void
    {
        foreach ([[402, [], 'rejected'], [422, [], 'rejected'], [409, ['success' => false, 'message' => 'Insufficient stock'], 'rejected'], [409, ['success' => false, 'message' => 'Unknown conflict'], 'ambiguous'], [500, [], 'ambiguous']] as [$status, $body, $outcome]) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(fn () => Http::response($body, $status));
            $this->assertSame($outcome, app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'original-reference')->outcome);
            Http::assertSentCount(1);
        }
        Http::swap(new Factory);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout');
        });
        $this->assertSame('ambiguous', app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'original-reference')->outcome);
        $this->assertSame(1, $attempts);
    }

    public function test_currency_mismatch_and_echoed_secret_fail_closed(): void
    {
        foreach ([['success' => true, 'data' => ['balance' => 100000, 'currency' => 'USD']], ['success' => true, 'data' => ['balance' => 100000, 'currency' => 'VND'], 'debug' => 'synthetic-nastele-secret']] as $payload) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(fn () => Http::response($payload));
            try {
                app(SupplierClient::class)->snapshot($this->supplier());
                $this->fail('Untrusted snapshot must be rejected.');
            } catch (SupplierUnavailable $exception) {
                $this->assertContains($exception->errorCode, ['invalid_payload', 'currency_mismatch']);
                $this->assertStringNotContainsString('synthetic-nastele-secret', $exception->getMessage());
            }
        }
    }

    public function test_rate_limit_cooldown_blocks_subsequent_purchase_without_http(): void
    {
        Http::fake(fn () => Http::response(['success' => false], 429, ['Retry-After' => '120']));
        $client = app(SupplierClient::class);
        $this->assertSame('rejected', $client->purchase($this->supplier(), $this->item(), 2, 'original-reference')->outcome);
        $this->assertSame('rate_limited', $client->purchase($this->supplier(), $this->item(), 2, 'second-reference')->errorCode);
        Http::assertSentCount(1);
    }

    public function test_duplicate_sku_and_wholesale_thresholds_cannot_publish_an_unsafe_catalog(): void
    {
        foreach (['duplicate_sku', 'duplicate_tier', 'negative_price', 'tier_limit'] as $case) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(function ($request) use ($case) {
                if (str_ends_with($request->url(), '/balance')) {
                    return Http::response(['success' => true, 'data' => ['balance' => 0, 'currency' => 'VND']]);
                }
                $row = ['id' => 43, 'name' => 'Synthetic SKU', 'price' => 30000, 'stock' => 100,
                    'wholesale_tiers' => [['minQuantity' => 10, 'unitPrice' => 28000]]];
                if ($case === 'duplicate_tier') {
                    $row['wholesale_tiers'][] = ['minQuantity' => 10, 'unitPrice' => 26000];
                } elseif ($case === 'negative_price') {
                    $row['price'] = -1;
                } elseif ($case === 'tier_limit') {
                    $row['wholesale_tiers'] = array_fill(0, 101, $row['wholesale_tiers'][0]);
                }

                return Http::response(['success' => true, 'data' => $case === 'duplicate_sku' ? [$row, $row] : [$row]]);
            });
            try {
                app(SupplierClient::class)->snapshot($this->supplier());
                $this->fail('Invalid catalog must not publish.');
            } catch (SupplierUnavailable $exception) {
                $this->assertSame('invalid_payload', $exception->errorCode);
            }
        }
    }

    public function test_financial_transaction_prevents_supplier_network_io(): void
    {
        Http::fake();
        DB::beginTransaction();
        try {
            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'original-reference');
            $this->assertSame('financial_lock', $result->errorCode);
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
    }
}
