<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CanbosoSupplierTest extends TestCase
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
        return (new Supplier)->forceFill(['id' => 123, 'provider' => 'canboso', 'api_key' => 'synthetic-buyer-key', 'currency' => 'USD', 'is_active' => true]);
    }

    private function item(): SupplierCatalogItem
    {
        return (new SupplierCatalogItem)->forceFill(['supplier_id' => 123, 'external_id' => 'sku-1', 'currency' => 'USD', 'orderable' => true, 'requires_input' => false]);
    }

    private function purchasePayload(): array
    {
        return ['success' => true, 'order' => ['orderCode' => 'ORDER1', 'status' => 'completed', 'productId' => 'sku-1', 'productType' => 'account', 'quantity' => 1, 'bonusQuantity' => 0, 'finalQuantity' => 1], 'payment' => ['amount' => '0.05', 'currency' => 'USD'], 'delivery' => ['accounts' => [['user' => 'synthetic@example.test', 'password' => 'synthetic-password', 'verifyEmail' => 'recovery@example.test', 'otherInfo' => 'synthetic-token']]]];
    }

    public function test_catalog_uses_exact_usd_costs_and_blocks_input_products(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('synthetic-buyer-key', $request['key']);
            if (str_contains($request->url(), '/balance?')) {
                return Http::response('{"success":true,"walletCurrency":"USD","balance":1.23456789}');
            }
            $rows = [];
            foreach (['account', 'slot', 'upgrade_account'] as $type) {
                $rows[] = ['productId' => $type, 'name' => $type, 'productType' => $type, 'price' => ['amount' => '0.05', 'currency' => 'USD'], 'availability' => ['available' => 81], 'promotions' => []];
            }

            return Http::response(['success' => true, 'walletCurrency' => 'USD', 'products' => $rows]);
        });
        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());
        $this->assertSame(1234567, $snapshot->balanceMicros);
        $this->assertSame(50000, $snapshot->products[0]->unitPriceMicros);
        $this->assertSame(81, $snapshot->products[0]->stock);
        $this->assertTrue($snapshot->products[0]->orderable);
        foreach (array_slice($snapshot->products, 1) as $product) {
            $this->assertTrue($product->requiresInput);
            $this->assertFalse($product->orderable);
        }
    }

    public function test_wallet_currency_mismatch_cannot_authorize_checkout(): void
    {
        Http::fake(fn () => Http::response(['success' => true, 'walletCurrency' => 'VND', 'balance' => 100000, 'products' => []]));
        $this->expectException(SupplierUnavailable::class);
        $this->expectExceptionMessage('currency_mismatch');
        app(SupplierClient::class)->snapshot($this->supplier());
    }

    public function test_purchase_delivers_correlated_credentials_and_exact_cost(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://canboso.com/api/v2/telegram-buyer/purchase', $request->url());
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'checkout-1'));
            $this->assertSame('sku-1', $request['product_id']);
            $this->assertSame('synthetic-buyer-key', $request['key']);

            return Http::response($this->purchasePayload());
        });
        $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'checkout-1');
        $this->assertSame('delivered', $result->outcome);
        $this->assertSame(50000, $result->costMicros);
        $this->assertSame(["synthetic@example.test\nsynthetic-password\nverifyEmail: recovery@example.test\notherInfo: synthetic-token"], $result->deliveries);
        $this->assertTrue($result->identityTrusted);
        $this->assertTrue($result->deliveryTrusted);
    }

    public function test_wrong_identity_missing_accounts_and_bonus_never_authorize_delivery(): void
    {
        foreach (['identity', 'missing', 'bonus', 'duplicate'] as $case) {
            Cache::flush();
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $payload = $this->purchasePayload();
            if ($case === 'identity') {
                $payload['order']['productId'] = 'foreign-sku';
            } elseif ($case === 'missing') {
                unset($payload['delivery']);
            } elseif ($case === 'bonus') {
                $payload['order']['bonusQuantity'] = 1;
                $payload['order']['finalQuantity'] = 2;
            } else {
                $payload['delivery']['accounts'][] = $payload['delivery']['accounts'][0];
            }
            Http::fake(fn () => Http::response($payload));
            $result = app(SupplierClient::class)->purchase($this->supplier(), $this->item(), 1, 'checkout-1');
            $this->assertSame('ambiguous', $result->outcome);
            $this->assertFalse($result->deliveryTrusted);
            if ($case === 'bonus') {
                $this->assertSame('unexpected_delivery_quantity', $result->errorCode);
                $this->assertSame(["synthetic@example.test\nsynthetic-password\nverifyEmail: recovery@example.test\notherInfo: synthetic-token"], $result->deliveries);
            }
        }
    }

    public function test_completed_history_without_credentials_requires_review(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('ORDER1', $request['orderCode']);

            return Http::response(['success' => true, 'orders' => [['orderCode' => 'ORDER1', 'status' => 'completed', 'quantity' => 1]]]);
        });
        $result = app(SupplierClient::class)->order($this->supplier(), 'ORDER1', 'sku-1');
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('delivery_unavailable', $result->errorCode);
        $this->assertFalse($result->deliveryTrusted);
        Http::assertSentCount(1);
    }

    public function test_rate_limit_respects_six_hour_penalty_without_second_http_request(): void
    {
        Http::fake(fn () => Http::response(['success' => false], 429, ['Retry-After' => '21600']));
        $client = app(SupplierClient::class);
        $result = $client->purchase($this->supplier(), $this->item(), 1, 'checkout-1');
        $this->assertSame('rejected', $result->outcome);
        try {
            $client->snapshot($this->supplier());
            $this->fail('Cooldown must block the read.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame(21600, $exception->retryAfterSeconds);
        }
        Http::assertSentCount(1);
    }
}
