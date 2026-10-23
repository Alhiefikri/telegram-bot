<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierExchangeRate;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MiraiSupplierTest extends TestCase
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

    private function supplier(array $overrides = []): Supplier
    {
        return (new Supplier)->forceFill(array_replace([
            'id' => 321,
            'provider' => 'miraiapi',
            'api_key' => 'synthetic-mirai-secret',
            'currency' => 'VND',
            'exchange_rate_idr' => '0.650001',
            'is_active' => true,
        ], $overrides));
    }

    private function item(): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill([
            'supplier_id' => 321,
            'external_id' => 'GPT_10M',
            'currency' => 'VND',
            'unit_price_micros' => 6_300_000_000,
            'orderable' => true,
            'requires_input' => false,
        ]);
    }

    private function orderPayload(array $overrides = []): array
    {
        return ['success' => true, 'data' => array_replace([
            'order_id' => 'ord-321',
            'external_order_id' => 'reference-321',
            'package_code' => 'GPT_10M',
            'quantity' => 2,
            'status' => 'fulfilled',
            'total_vnd' => 12600,
            'redeem_codes' => ['mirai-code-a', 'mirai-code-b'],
            'redeem_code' => 'mirai-code-a',
        ], $overrides)];
    }

    public function test_snapshot_reads_bearer_balance_and_packages_into_vnd_units(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic-mirai-secret'));
            $this->assertFalse($options['allow_redirects']);
            if ($request->url() === 'https://api.miraiapi.com/api/reseller/v1/balance') {
                return Http::response(['data' => ['balance_vnd' => 100000, 'discount_bps' => 1000], 'success' => true]);
            }
            $this->assertSame('https://api.miraiapi.com/api/reseller/v1/packages', $request->url());

            return Http::response(['data' => [
                ['code' => 'GPT_10M', 'name' => 'GPT 10M tokens · 1D', 'reseller_price_vnd' => 6300, 'min_quantity' => 1, 'max_quantity' => 10000, 'remaining_today' => 0, 'unlimited' => true],
                ['code' => 'TAU_50M', 'name' => 'Trung Quốc 50M tokens · 2D', 'reseller_price_vnd' => 18000, 'min_quantity' => 1, 'max_quantity' => 10, 'remaining_today' => 3, 'unlimited' => false],
                ['code' => 'SOON', 'name' => 'Sold out package', 'reseller_price_vnd' => 1000, 'min_quantity' => 1, 'max_quantity' => 5, 'remaining_today' => 0, 'unlimited' => false],
                ['code' => 'BULK_ONLY', 'name' => 'Minimum five', 'reseller_price_vnd' => 1000, 'min_quantity' => 5, 'max_quantity' => 10, 'remaining_today' => 10, 'unlimited' => false],
            ], 'success' => true]);
        });

        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());

        $this->assertSame(100_000_000_000, $snapshot->balanceMicros);
        $this->assertSame('VND', $snapshot->currency);
        $this->assertCount(4, $snapshot->products);
        $this->assertSame('GPT_10M', $snapshot->products[0]->externalId);
        $this->assertSame(6_300_000_000, $snapshot->products[0]->unitPriceMicros);
        $this->assertSame(10000, $snapshot->products[0]->stock);
        $this->assertTrue($snapshot->products[0]->orderable);
        $this->assertFalse($snapshot->products[0]->requiresInput);
        $this->assertSame(3, $snapshot->products[1]->stock);
        $this->assertTrue($snapshot->products[1]->orderable);
        $this->assertSame(0, $snapshot->products[2]->stock);
        $this->assertFalse($snapshot->products[2]->orderable);
        $this->assertSame(0, $snapshot->products[3]->stock);
        $this->assertFalse($snapshot->products[3]->orderable);
        $this->assertTrue(SupplierExchangeRate::isPlausible('VND', '0.650001'));
        Http::assertSentCount(2);
    }

    public function test_create_uses_the_documented_payload_and_delivers_every_redeem_code(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->assertSame('https://api.miraiapi.com/api/reseller/v1/orders', $request->url());
                $this->assertSame([
                    'package_code' => 'GPT_10M',
                    'quantity' => 2,
                    'external_order_id' => 'reference-321',
                ], $request->data());
                $this->assertTrue($request->hasHeader('Idempotency-Key', 'reference-321'));
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic-mirai-secret'));

                return Http::response($this->orderPayload());
            }
            $this->assertSame('https://api.miraiapi.com/api/reseller/v1/orders/ord-321', $request->url());

            return Http::response($this->orderPayload());
        });
        $client = app(SupplierClient::class);

        foreach ([
            $client->purchase($this->supplier(), $this->item(), 2, 'reference-321'),
            $client->order($this->supplier(), 'ord-321', 'GPT_10M', 'reference-321'),
        ] as $result) {
            $this->assertSame('delivered', $result->outcome);
            $this->assertSame('ord-321', $result->orderId);
            $this->assertSame(2, $result->quantity);
            $this->assertSame(12_600_000_000, $result->costMicros);
            $this->assertSame(['mirai-code-a', 'mirai-code-b'], $result->deliveries);
            $this->assertTrue($result->identityTrusted);
            $this->assertTrue($result->deliveryTrusted);
        }
    }

    public function test_legacy_single_code_cannot_complete_a_multi_unit_order(): void
    {
        Http::fake(fn () => Http::response($this->orderPayload([
            'redeem_codes' => ['mirai-legacy-code'],
            'redeem_code' => 'mirai-legacy-code',
        ])));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');

        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('ord-321', $result->orderId);
        $this->assertSame(['mirai-legacy-code'], $result->deliveries);
        $this->assertSame('incomplete_delivery', $result->errorCode);
        $this->assertTrue($result->identityTrusted);
        $this->assertFalse($result->deliveryTrusted);
    }

    public function test_quantity_can_be_inferred_from_a_complete_code_array(): void
    {
        $payload = $this->orderPayload();
        unset($payload['data']['quantity']);
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');

        $this->assertSame('delivered', $result->outcome);
        $this->assertNull($result->quantity);
        $this->assertSame(12_600_000_000, $result->costMicros);
        $this->assertCount(2, $result->deliveries);
        $this->assertTrue($result->deliveryTrusted);
    }

    public function test_pending_and_unknown_statuses_retain_identity_without_delivery_trust(): void
    {
        foreach ([
            'pending' => ['pending', true, null],
            'processing' => ['processing', true, null],
            'unknown' => ['mystery', true, 'invalid_order_status'],
            'missing' => [null, true, 'invalid_order_status'],
        ] as $case => [$status, $identityTrusted, $errorCode]) {
            Cache::flush();
            Http::swap(new Factory);
            $payload = $this->orderPayload();
            if ($status === null) {
                unset($payload['data']['status']);
            } else {
                $payload['data']['status'] = $status;
            }
            Http::fake(fn () => Http::response($payload));

            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');
            $this->assertSame($case === 'pending' || $case === 'processing' ? 'pending' : 'ambiguous', $result->outcome, $case);
            $this->assertSame($errorCode, $result->errorCode, $case);
            $this->assertSame($identityTrusted, $result->identityTrusted, $case);
            $this->assertFalse($result->deliveryTrusted, $case);
        }
    }

    public function test_duplicate_codes_and_mismatched_evidence_never_authorize_delivery(): void
    {
        foreach ([
            'duplicate' => [['redeem_codes' => ['mirai-code-a', 'mirai-code-a'], 'redeem_code' => 'mirai-code-a'], 'duplicate_delivery'],
            'external_reference' => [['external_order_id' => 'another-reference'], 'payload_mismatch'],
            'package' => [['package_code' => 'CLAUDE_10M'], 'payload_mismatch'],
            'quantity' => [['quantity' => 3], 'payload_mismatch'],
        ] as $case => [$overrides, $errorCode]) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(fn () => Http::response($this->orderPayload($overrides)));

            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');
            $this->assertSame('ambiguous', $result->outcome, $case);
            $this->assertSame($errorCode, $result->errorCode, $case);
            $this->assertFalse($result->deliveryTrusted, $case);
        }
    }

    public function test_exact_order_identity_must_match(): void
    {
        Http::fake(fn () => Http::response($this->orderPayload()));

        $wrong = app(SupplierClient::class)->order($this->supplier(), 'other-order', 'GPT_10M', 'reference-321');
        $this->assertSame('ambiguous', $wrong->outcome);
        $this->assertSame('payload_mismatch', $wrong->errorCode);
        $this->assertFalse($wrong->identityTrusted);
        $this->assertFalse($wrong->deliveryTrusted);
    }

    public function test_alias_mismatch_does_not_bypass_package_reference_or_exact_order_correlation(): void
    {
        foreach ([
            'package' => ['package_code' => 'OTHER'],
            'reference' => ['external_order_id' => 'other-reference'],
            'order' => ['order_id' => 'other-order'],
        ] as $case => $overrides) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(fn () => Http::response($this->orderPayload($overrides + ['redeem_code' => 'other-code'])));
            $client = app(SupplierClient::class);
            $result = $case === 'order'
                ? $client->order($this->supplier(), 'ord-321', 'GPT_10M', 'reference-321')
                : $client->purchase($this->supplier(), $this->item(), 2, 'reference-321');

            $this->assertSame('ambiguous', $result->outcome, $case);
            $this->assertFalse($result->identityTrusted, $case);
            $this->assertFalse($result->deliveryTrusted, $case);
        }
    }

    public function test_complete_delivery_without_charged_total_does_not_use_catalogue_cost(): void
    {
        $payload = $this->orderPayload();
        unset($payload['data']['total_vnd']);
        Http::fake(fn () => Http::response($payload));

        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');

        $this->assertTrue($result->identityTrusted);
        $this->assertTrue($result->deliveryTrusted);
        $this->assertNull($result->costMicros);
    }

    public function test_documented_rejections_are_safe_and_unknown_conflicts_are_ambiguous(): void
    {
        foreach ([
            [400, ['message' => 'external_order_id, package_code and Idempotency-Key are required', 'success' => false], 'rejected'],
            [409, ['message' => 'insufficient wallet balance', 'success' => false], 'rejected'],
            [409, ['message' => 'package is not available', 'success' => false], 'rejected'],
            [401, [], 'rejected'],
            [403, [], 'rejected'],
            [404, [], 'rejected'],
            [422, [], 'rejected'],
            [429, [], 'rejected'],
            [402, ['message' => 'insufficient wallet balance', 'success' => false], 'ambiguous'],
            [409, ['message' => 'insufficient balance', 'success' => false], 'ambiguous'],
            [409, ['message' => 'package not found', 'success' => false], 'ambiguous'],
            [409, ['message' => 'unexpected idempotency conflict', 'success' => false], 'ambiguous'],
            [500, [], 'ambiguous'],
        ] as [$status, $body, $outcome]) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(fn () => Http::response($body, $status));
            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321');
            $this->assertSame($outcome, $result->outcome, (string) $status);
            Http::assertSentCount(1);
        }

        Http::swap(new Factory);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout');
        });
        $this->assertSame('ambiguous', app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 2, 'reference-321')->outcome);
        $this->assertSame(1, $attempts);
    }

    public function test_rate_limit_cooldown_blocks_the_next_purchase_without_http(): void
    {
        Http::fake(fn () => Http::response(['success' => false], 429, ['Retry-After' => '120']));
        $client = app(SupplierClient::class);

        $this->assertSame('rejected', $client->purchase($this->supplier(), $this->item(), 2, 'reference-321')->outcome);
        $this->assertSame('rate_limited', $client->purchase($this->supplier(), $this->item(), 2, 'reference-322')->errorCode);
        Http::assertSentCount(1);
    }

    public function test_currency_mismatch_and_key_echo_fail_closed(): void
    {
        try {
            app(SupplierClient::class)->snapshot($this->supplier(['currency' => 'USD']));
            $this->fail('A non-VND Mirai connection must be rejected.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('currency_mismatch', $exception->errorCode);
        }

        $row = ['code' => 'GPT_10M', 'name' => 'A', 'reseller_price_vnd' => 1, 'min_quantity' => 1, 'max_quantity' => 1, 'remaining_today' => 0, 'unlimited' => true];
        foreach ([
            ['success' => true, 'data' => [$row], 'debug' => 'synthetic-mirai-secret'],
            ['success' => true, 'data' => [$row, $row]],
        ] as $catalog) {
            Cache::flush();
            Http::swap(new Factory);
            Http::fake(function ($request) use ($catalog) {
                return str_ends_with($request->url(), '/balance')
                    ? Http::response(['success' => true, 'data' => ['balance_vnd' => 1]])
                    : Http::response($catalog);
            });
            try {
                app(SupplierClient::class)->snapshot($this->supplier());
                $this->fail('Untrusted snapshots must be rejected.');
            } catch (SupplierUnavailable $exception) {
                $this->assertContains($exception->errorCode, ['invalid_payload', 'currency_mismatch']);
                $this->assertStringNotContainsString('synthetic-mirai-secret', $exception->getMessage());
            }
        }
    }
}
