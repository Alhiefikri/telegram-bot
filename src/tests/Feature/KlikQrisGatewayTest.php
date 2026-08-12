<?php

namespace Tests\Feature;

use App\Enums\KlikQrisEnvironment;
use App\Services\KlikQris\KlikQrisClient;
use App\Services\KlikQris\KlikQrisCreateOutcomeUnknownException;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
    }

    public function test_provider_adapter_is_bound_to_sandbox_client(): void
    {
        $this->assertInstanceOf(KlikQrisClient::class, app(KlikQrisProviderAdapter::class));
    }

    public function test_explicit_production_client_never_falls_back_to_sandbox_endpoint_or_credentials(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'qris_url' => 'https://api.klikqris.example/qris/production.png',
        ]), 200));

        $client = new KlikQrisClient(app(KlikQrisProviderReadiness::class), KlikQrisEnvironment::Production);
        $transaction = $client->createTransaction($payment);

        $this->assertSame(KlikQrisEnvironment::Production, $transaction->environment());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.klikqris.example/qris/create'
            && $request->hasHeader('x-api-key', 'production-test-key')
            && $request->hasHeader('id_merchant', 'production-merchant-test')
            && $request['id_merchant'] === 'production-merchant-test'
            && $request['callback_url'] === 'https://store.example.test/api/webhook/klikqris'
            && ! $request->hasHeader('X-KLIKQRIS-SIGNATURE')
            && ! $request->hasHeader('X-Signature'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'klikqris.com/api/sandbox'));
    }

    public function test_create_transaction_posts_exact_sandbox_contract_once(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        $transaction = app(KlikQrisProviderAdapter::class)->createTransaction($payment);

        $this->assertSame($payment->merchant_ref, $transaction->orderId());
        $this->assertSame($payment->amount + 16, $transaction->totalAmount());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://klikqris.com/api/sandbox/qris/create'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('x-api-key', 'test-only-key')
            && $request->hasHeader('id_merchant', 'merchant-test')
            && $request['order_id'] === $payment->merchant_ref
            && $request['id_merchant'] === 'merchant-test'
            && $request['amount'] === $payment->amount
            && $request['keterangan'] === 'Payment '.$payment->merchant_ref);
    }

    public function test_status_transaction_gets_rawurlencoded_order_without_credentials_in_query(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $transaction = app(KlikQrisProviderAdapter::class)->transactionStatus($payment);

        $this->assertTrue($transaction->isSuccess());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://klikqris.com/api/sandbox/qris/status/'.rawurlencode($payment->merchant_ref)
            && $request->hasHeader('x-api-key', 'test-only-key')
            && $request->hasHeader('id_merchant', 'merchant-test')
            && ! str_contains($request->url(), 'test-only-key')
            && ! str_contains($request->url(), 'merchant-test'));
    }

    public function test_create_connection_failure_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('connection failed');
        });

        try {
            app(KlikQrisProviderAdapter::class)->createTransaction(KlikQrisSandboxScenarios::klikQrisPayment());
            $this->fail('Expected KlikQRIS create connection failure.');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(KlikQrisCreateOutcomeUnknownException::class, $exception);
            $this->assertStringContainsString('KlikQRIS create outcome is unknown', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_status_retries_transient_http_failures_only(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fakeSequence()
            ->push(['message' => 'temporary'], 500)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment), 200);

        $transaction = app(KlikQrisProviderAdapter::class)->transactionStatus($payment);

        $this->assertTrue($transaction->isSuccess());
        Http::assertSentCount(2);
    }

    public function test_status_retries_request_timeout(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fakeSequence()
            ->push(['message' => 'request timeout'], 408)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment), 200);

        $transaction = app(KlikQrisProviderAdapter::class)->transactionStatus($payment);

        $this->assertTrue($transaction->isSuccess());
        Http::assertSentCount(2);
    }

    public function test_status_retries_connection_errors(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        $attempts = 0;
        Http::fake(function () use (&$attempts, $payment) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('connection failed');
            }

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200);
        });

        $transaction = app(KlikQrisProviderAdapter::class)->transactionStatus($payment);

        $this->assertTrue($transaction->isSuccess());
        $this->assertSame(2, $attempts);
    }

    public function test_status_retries_rate_limit_but_not_unauthorized(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fakeSequence()
            ->push(['message' => 'rate limited'], 429)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment), 200);

        $this->assertTrue(app(KlikQrisProviderAdapter::class)->transactionStatus($payment)->isSuccess());
        Http::assertSentCount(2);
    }

    public function test_status_unauthorized_does_not_retry(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake(fn () => Http::response(['message' => 'no'], 401));

        try {
            app(KlikQrisProviderAdapter::class)->transactionStatus($payment);
            $this->fail('Expected KlikQRIS status 401 to fail without retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_invalid_json_exception_is_sanitized(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        $secrets = ['test-only-key', 'merchant-test', 'opaque-test-signature', 'secret-response-body', 'Bearer secret-authorization'];

        Http::fake(fn () => Http::response('secret-response-body', 200, ['authorization' => 'Bearer secret-authorization']));
        $this->assertSanitizedFailure(fn () => app(KlikQrisProviderAdapter::class)->createTransaction($payment), $secrets, 'KlikQRIS create outcome is unknown');
    }

    public function test_mismatched_payload_exception_is_sanitized(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        $secrets = ['test-only-key', 'merchant-test', 'opaque-test-signature', 'secret-response-body', 'Bearer secret-authorization'];

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'order_id' => $payment->merchant_ref.'-wrong',
            'api_key' => 'test-only-key',
            'signature' => 'opaque-test-signature',
        ]), 200));
        $this->assertSanitizedFailure(fn () => app(KlikQrisProviderAdapter::class)->createTransaction($payment), $secrets, 'KlikQRIS create outcome is unknown');
    }

    public function test_readiness_failure_happens_before_any_http_request(): void
    {
        config()->set('services.klikqris.sandbox_api_key', null);
        Http::fake();

        $this->assertSanitizedFailure(
            fn () => app(KlikQrisProviderAdapter::class)->createTransaction(KlikQrisSandboxScenarios::klikQrisPayment()),
            ['test-only-key', 'merchant-test'],
            'KlikQRIS create request was not sent'
        );
        Http::assertNothingSent();
    }

    /** @param list<string> $forbidden */
    private function assertSanitizedFailure(callable $callback, array $forbidden, string $expectedMessage): void
    {
        try {
            $callback();
            $this->fail('Expected sanitized KlikQRIS failure.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString($expectedMessage, $message);
            foreach ($forbidden as $secret) {
                $this->assertStringNotContainsString($secret, $message);
            }
        }
    }
}
