<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Services\Suppliers\SupplierClient;
use App\Services\Suppliers\SupplierUnavailable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierSnapshotTransportTest extends TestCase
{
    private const KEY = 'synthetic-private-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.limiter' => 'array']);
        Cache::flush();
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('array')));
        Http::preventStrayRequests();
    }

    public static function connectionFailures(): array
    {
        return [
            'dns failure' => [6, 'cURL error 6: Could not resolve host', 'dns'],
            'TLS failure' => [35, 'cURL error 35: TLS handshake failed', 'tls'],
            'connect timeout' => [28, 'cURL error 28: Connection timed out', 'connect_timeout'],
            'connection reset' => [56, 'cURL error 56: Connection reset by peer', 'connection_reset'],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function test_snapshot_retries_failed_read_once_and_recovers(int $errno, string $message, string $type): void
    {
        $calls = [];
        $logs = &$this->captureWarnings();
        Http::fake(function ($request, $options) use (&$calls, $errno, $message) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/api/v1'));
            $calls[] = $path;
            $this->assertLessThanOrEqual(12, $options['timeout']);
            $this->assertLessThanOrEqual(4, $options['connect_timeout']);
            if ($path === '/me' && count($calls) === 1) {
                return $this->connectionFailure($errno, $message);
            }

            return Http::response($path === '/me' ? ['balance' => '42.75'] : [
                'services' => [['service_id' => 'sku-1', 'name' => 'Fixture', 'price' => '5', 'stock' => 7]],
            ]);
        });

        $snapshot = app(SupplierClient::class)->snapshot($this->supplier());

        $this->assertSame(['/me', '/me', '/products'], $calls);
        $this->assertSame(42_750_000, $snapshot->balanceMicros);
        $this->assertSame('sku-1', $snapshot->products[0]->externalId);
        $this->assertSafeDiagnostic($logs, $type, 1);
    }

    #[DataProvider('connectionFailures')]
    public function test_snapshot_stops_after_second_connection_failure_without_exposing_secrets(int $errno, string $message, string $type): void
    {
        $calls = 0;
        $logs = &$this->captureWarnings();
        Http::fake(function () use (&$calls, $errno, $message) {
            $calls++;

            return $this->connectionFailure($errno, $message);
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier());
            $this->fail('A failed snapshot must not publish a catalog.');
        } catch (SupplierUnavailable $exception) {
            $this->assertContains($exception->errorCode, ['transport', 'request_timeout']);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }

        $this->assertSame(2, $calls);
        $this->assertSafeDiagnostic($logs, $type, 1);
        $this->assertSafeDiagnostic($logs, $type, 2);
    }

    public function test_snapshot_does_not_retry_a_non_connection_transfer_failure(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            $request = new Request('GET', 'https://supplier.example.test/');

            return Create::rejectionFor(new RequestException('write failed', $request, null, null, ['errno' => 23]));
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier());
            $this->fail('A failed transfer must not publish a catalog.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('transport', $exception->errorCode);
        }
        $this->assertSame(1, $calls);
    }

    #[DataProvider('httpFailures')]
    public function test_snapshot_does_not_retry_http_errors(int $status, string $code): void
    {
        $calls = 0;
        $logs = &$this->captureWarnings();
        Http::fake(function () use (&$calls, $status) {
            $calls++;

            return Http::response(['error' => 'private provider body'], $status, ['Retry-After' => '120']);
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier());
            $this->fail('An HTTP failure must not publish a catalog.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
        $this->assertSame(1, $calls);
        $this->assertSame($status, $logs[0][1]['http_status']);
        $this->assertSame($code, $logs[0][1]['error_type']);
        $this->assertStringNotContainsString('private provider body', json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public static function httpFailures(): array
    {
        return [[429, 'rate_limited'], [500, 'upstream'], [503, 'upstream']];
    }

    #[DataProvider('connectionFailures')]
    public function test_purchase_connection_failure_is_ambiguous_and_never_retried(int $errno, string $message, string $type): void
    {
        $supplier = $this->supplier();
        $item = (new SupplierCatalogItem)->forceFill([
            'id' => 77, 'supplier_id' => $supplier->id, 'external_id' => 'sku-1',
            'currency' => 'USD', 'orderable' => true, 'requires_input' => false,
        ]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls, $errno, $message) {
            $calls++;
            $this->assertSame('POST', $request->method());

            return $this->connectionFailure($errno, $message);
        });

        $result = app(SupplierClient::class)->purchase($supplier, $item, 1, 'private-checkout-reference');

        $this->assertSame(1, $calls);
        $this->assertSame('ambiguous', $result->outcome);
        $this->assertSame('transport', $result->errorCode);
        $this->assertFalse($result->deliveryTrusted);
        $this->assertStringNotContainsString(self::KEY, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_pooled_detail_retries_only_the_failed_connection(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/v1'));
            $calls[$path] = ($calls[$path] ?? 0) + 1;
            if ($path === '/products/sku-1' && $calls[$path] === 1) {
                return $this->connectionFailure(56, 'cURL error 56: Connection reset by peer');
            }

            return Http::response($this->prodSellerResponse($path));
        });

        $snapshot = app(SupplierClient::class)->snapshot($this->supplier('prodseller'));

        $this->assertSame(25_500_000, $snapshot->balanceMicros);
        $this->assertCount(2, $snapshot->products);
        $this->assertSame(2, $calls['/products/sku-1']);
        $this->assertSame(1, $calls['/products/sku-2']);
        $this->assertSame(1, $calls['/balance']);
        $this->assertSame(1, $calls['/products']);
    }

    public function test_pooled_detail_failure_after_retry_rejects_the_snapshot(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/v1'));
            $calls[$path] = ($calls[$path] ?? 0) + 1;
            if ($path === '/products/sku-1') {
                return $this->connectionFailure(56, 'cURL error 56: Connection reset by peer');
            }

            return Http::response($this->prodSellerResponse($path));
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier('prodseller'));
            $this->fail('A failed detail must reject the entire snapshot.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('transport', $exception->errorCode);
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(2, $calls['/products/sku-1']);
        $this->assertSame(1, $calls['/products/sku-2']);
    }

    public function test_pooled_rate_limit_takes_precedence_over_connection_retry(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $path = substr(parse_url($request->url(), PHP_URL_PATH), strlen('/v1'));
            $calls[$path] = ($calls[$path] ?? 0) + 1;
            if ($path === '/products/sku-1') {
                return $this->connectionFailure(56, 'cURL error 56: Connection reset by peer');
            }
            if ($path === '/products/sku-2') {
                return Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '120']);
            }

            return Http::response($this->prodSellerResponse($path));
        });

        try {
            app(SupplierClient::class)->snapshot($this->supplier('prodseller'));
            $this->fail('A rate-limited detail must reject the entire snapshot.');
        } catch (SupplierUnavailable $exception) {
            $this->assertSame('rate_limited', $exception->errorCode);
        }
        $this->assertSame(1, $calls['/products/sku-1']);
        $this->assertSame(1, $calls['/products/sku-2']);
    }

    private function supplier(string $provider = 'csxstore'): Supplier
    {
        return (new Supplier)->forceFill([
            'id' => 123, 'name' => 'Private supplier', 'provider' => $provider,
            'api_key' => self::KEY, 'currency' => $provider === 'prodseller' ? 'USDT' : 'USD',
            'currency_confirmed' => true, 'is_active' => true,
        ]);
    }

    private function connectionFailure(int $errno, string $message): mixed
    {
        $request = new Request('GET', 'https://supplier.example.test/private-path?key='.self::KEY);

        return Create::rejectionFor(new ConnectException($message.' '.self::KEY.' private-provider-body Cookie: sensitive-cookie Authorization: sensitive-header', $request, null, [
            'errno' => $errno, 'connect_time' => $errno === 28 ? 0 : 0.1,
        ]));
    }

    private function prodSellerResponse(string $path): array
    {
        return match ($path) {
            '/balance' => ['balance' => '25.5'],
            '/products' => ['products' => [
                ['id' => 'sku-1', 'name' => 'First', 'price' => '4.99'],
                ['id' => 'sku-2', 'name' => 'Second', 'price' => '4.99'],
            ]],
            '/products/sku-1' => ['id' => 'sku-1', 'name' => 'First', 'price' => '4.99', 'stock' => 47],
            '/products/sku-2' => ['id' => 'sku-2', 'name' => 'Second', 'price' => '4.99', 'stock' => 12],
        };
    }

    private function &captureWarnings(): array
    {
        $warnings = [];
        Log::shouldReceive('warning')->zeroOrMoreTimes()->andReturnUsing(function ($message, $context = []) use (&$warnings) {
            $warnings[] = [$message, $context];
        });

        return $warnings;
    }

    private function assertSafeDiagnostic(array $warnings, string $type, int $attempt): void
    {
        $matching = array_filter($warnings, fn ($warning) => $warning[0] === 'Supplier snapshot request failed'
            && ($warning[1]['error_type'] ?? null) === $type && ($warning[1]['attempt'] ?? null) === $attempt);
        $this->assertNotEmpty($matching);
        foreach ($warnings as [$message, $context]) {
            $serialized = json_encode([$message, $context], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::KEY, $serialized);
            $this->assertStringNotContainsString('private-provider-body', $serialized);
            $this->assertStringNotContainsString('sensitive-cookie', $serialized);
            $this->assertStringNotContainsString('sensitive-header', $serialized);
            $this->assertStringNotContainsString('private-path', $serialized);
        }
        $context = array_values($matching)[0][1];
        $this->assertSame(123, $context['supplier_id']);
        $this->assertSame('csxstore', $context['provider']);
        $this->assertNotEmpty($context['host']);
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertNull($context['http_status']);
    }
}
