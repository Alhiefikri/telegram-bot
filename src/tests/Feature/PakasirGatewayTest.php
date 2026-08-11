<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessProviderCancellationAttempt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProviderCancellationAttempt;
use App\Providers\AppServiceProvider;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirClient;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\QrisTestPayload;
use Tests\TestCase;

class PakasirGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://app.pakasir.com',
            'redirect_url' => 'https://example.test/payment/return',
            'qris_only' => true,
        ]);
    }

    public function test_generates_deterministic_hosted_payment_url_for_existing_payment(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame(
            'https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref.'&redirect=https%3A%2F%2Fexample.test%2Fpayment%2Freturn',
            $url,
        );
        $this->assertStringNotContainsString('api_key', $url);
    }

    public function test_hosted_payment_url_omits_optional_params_when_not_configured(): void
    {
        config()->set('services.pakasir.redirect_url', null);
        config()->set('services.pakasir.qris_only', false);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame('https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref, $url);
    }

    public function test_transaction_detail_request_uses_project_amount_order_id_and_api_key(): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response(['transaction' => ['status' => 'completed']], 200),
        ]);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $response = app(PakasirClient::class)->transactionDetail($payment);

        $this->assertSame(['transaction' => ['status' => 'completed']], $response);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.pakasir.com/api/transactiondetail?project=warung-ai&amount=10000&order_id='.$payment->merchant_ref.'&api_key=pakasir-secret');
    }

    public function test_cancel_transaction_posts_to_pakasir_with_expected_body(): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactioncancel' => Http::response(['transaction' => ['status' => 'cancelled']], 200),
        ]);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $response = app(PakasirClient::class)->cancelTransaction($payment);

        $this->assertSame(['transaction' => ['status' => 'cancelled']], $response);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://app.pakasir.com/api/transactioncancel'
            && $request['project'] === 'warung-ai'
            && $request['order_id'] === $payment->merchant_ref
            && $request['amount'] === 10000
            && $request['api_key'] === 'pakasir-secret');
    }

    public function test_expire_pending_orders_queues_provider_cancellation_after_releasing_local_pakasir_order(): void
    {
        config()->set('services.telegram.token', null);
        Queue::fake();
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::HOSTED, now()->subMinute()));
        $payment = $order->payments()->firstOrFail();

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('orders:expire-pending completed cleanly: processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $attempt = ProviderCancellationAttempt::query()->sole();
        $this->assertSame(ProviderCancellationAttempt::STATE_PENDING, $attempt->state);
        Queue::assertPushed(ProcessProviderCancellationAttempt::class, fn (ProcessProviderCancellationAttempt $job): bool => $job->attemptId === $attempt->id);
        $this->assertSame(0, PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCancel)->count());
    }

    public function test_expire_pending_orders_does_not_call_pakasir_for_manual_payments(): void
    {
        config()->set('services.telegram.token', null);
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::manualQris(now()->subMinute()));

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        Http::assertNothingSent();
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_transaction_detail_uses_configured_timeouts_and_retries_transient_failure(): void
    {
        config()->set('services.pakasir.http_timeout_seconds', 4.5);
        config()->set('services.pakasir.http_connect_timeout_seconds', 1.5);
        config()->set('services.pakasir.transaction_detail_max_attempts', 2);
        config()->set('services.pakasir.transaction_detail_retry_delay_ms', 0);
        config()->set('services.pakasir.transaction_detail_retry_max_delay_ms', 0);
        $attempts = 0;
        $attemptOptions = [];

        Http::fake(function ($_, array $options) use (&$attempts, &$attemptOptions) {
            $attempts++;
            $attemptOptions[] = $options;

            if ($attempts === 1) {
                return Http::response(['message' => 'temporary provider failure'], 500);
            }

            return Http::response(['transaction' => ['status' => 'completed']], 200);
        });
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $response = app(PakasirClient::class)->transactionDetail($payment);

        $this->assertSame(['transaction' => ['status' => 'completed']], $response);
        $this->assertSame(2, $attempts);
        $this->assertCount(2, $attemptOptions);
        foreach ($attemptOptions as $options) {
            $this->assertSame(4.5, $options['timeout']);
            $this->assertSame(1.5, $options['connect_timeout']);
        }
        Http::assertSentCount(2);
    }

    public function test_creates_qris_transaction_posts_to_pakasir_without_mutating_local_payment_state(): void
    {
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        Http::fake(fn ($request) => Http::response([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $request['order_id'],
                'amount' => 10000,
                'fee' => 380,
                'total_payment' => 10380,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => '2026-07-04T16:30:00Z',
                'api_key' => 'provider-should-not-echo-this',
            ],
        ], 200));
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        $transaction = app(PakasirClient::class)->createQrisTransaction($payment);

        $this->assertSame('warung-ai', $transaction->project());
        $this->assertSame($payment->merchant_ref, $transaction->orderId());
        $this->assertSame(10000, $transaction->amount());
        $this->assertSame(380, $transaction->fee());
        $this->assertSame(10380, $transaction->totalPayment());
        $this->assertSame('qris', $transaction->paymentMethod());
        $this->assertSame($paymentNumber, $transaction->paymentNumber());
        $this->assertSame('2026-07-04T16:30:00+00:00', $transaction->expiresAt()?->utc()->toAtomString());
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://app.pakasir.com/api/transactioncreate/qris'
            && $request['project'] === 'warung-ai'
            && $request['order_id'] === $payment->merchant_ref
            && $request['amount'] === 10000
            && $request['api_key'] === 'pakasir-secret');

        $payment->refresh();
        $this->assertNull($payment->provider_payload);
        $this->assertNull($payment->provider_fee);
        $this->assertNull($payment->provider_total_payment);
        $this->assertNull($payment->provider_payment_method);
        $this->assertNull($payment->provider_payment_number);
        $this->assertNull($payment->provider_payment_number_hash);
        $this->assertNull($payment->provider_created_at);
        $this->assertNull($payment->provider_expires_at);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_qris_transaction_create_uses_configured_timeouts_without_automatic_retry(): void
    {
        config()->set('services.pakasir.http_timeout_seconds', 6.0);
        config()->set('services.pakasir.http_connect_timeout_seconds', 2.0);
        config()->set('services.pakasir.transaction_detail_max_attempts', 3);
        config()->set('services.pakasir.transaction_detail_retry_delay_ms', 0);
        config()->set('services.pakasir.transaction_detail_retry_max_delay_ms', 0);
        $attempts = 0;
        $attemptOptions = [];

        Http::fake(function ($_, array $options) use (&$attempts, &$attemptOptions) {
            $attempts++;
            $attemptOptions[] = $options;

            return Http::response(['message' => 'provider unavailable'], 500);
        });
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        try {
            app(PakasirClient::class)->createQrisTransaction($payment);
            $this->fail('Expected Pakasir QRIS create to fail without retrying.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response?->status());
        }

        $this->assertSame(1, $attempts);
        $this->assertCount(1, $attemptOptions);
        $this->assertSame(6.0, $attemptOptions[0]['timeout']);
        $this->assertSame(2.0, $attemptOptions[0]['connect_timeout']);
        Http::assertSentCount(1);

        $payment->refresh();
        $this->assertNull($payment->provider_payload);
        $this->assertNull($payment->provider_fee);
        $this->assertNull($payment->provider_total_payment);
        $this->assertNull($payment->provider_payment_method);
        $this->assertNull($payment->provider_payment_number);
        $this->assertNull($payment->provider_payment_number_hash);
        $this->assertNull($payment->provider_created_at);
        $this->assertNull($payment->provider_expires_at);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_native_qris_service_persists_sanitized_native_fields_from_provider_response(): void
    {
        Http::fake();
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        $expiresAt = now()->addMinutes(15);
        $providerSecretValues = [
            'provider-top-level-api-key',
            'provider-nested-x-api-key',
            'provider-nested-camel-api-key',
            'Bearer provider-authorization-token',
            'provider-signature',
        ];
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $providerPayload = [
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $payment->merchant_ref,
                'amount' => 10000,
                'fee' => 380,
                'total_payment' => 10380,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => $expiresAt->utc()->toAtomString(),
                'api_key' => $providerSecretValues[0],
                'provider_context' => [
                    'x-api-key' => $providerSecretValues[1],
                    'apiKey' => $providerSecretValues[2],
                    'authorization' => $providerSecretValues[3],
                    'signature' => $providerSecretValues[4],
                    'safe_note' => 'provider metadata is safe',
                ],
            ],
        ];

        $this->app->instance(PakasirProviderAdapter::class, new class($providerPayload) implements PakasirProviderAdapter
        {
            public function __construct(private readonly array $payload) {}

            public function hostedPaymentUrl(Payment $payment): string
            {
                throw new \BadMethodCallException('Hosted URL is not used by this test.');
            }

            public function createQrisTransaction(Payment $payment): PakasirQrisTransaction
            {
                return PakasirQrisTransaction::fromPayload($this->payload);
            }

            public function cancelTransaction(Payment $payment): array
            {
                throw new \BadMethodCallException('Transaction cancel is not used by this test.');
            }

            public function transactionDetail(Payment $payment): array
            {
                throw new \BadMethodCallException('Transaction detail is not used by this test.');
            }
        });

        $transaction = app(PakasirNativeQrisService::class)->ensureCreated($payment);

        $this->assertSame($paymentNumber, $transaction->paymentNumber());
        $payment->refresh();
        $this->assertSame(380, $payment->provider_fee);
        $this->assertSame(10380, $payment->provider_total_payment);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertSame($expiresAt->utc()->toAtomString(), $payment->provider_expires_at?->utc()->toAtomString());
        $this->assertNotNull($payment->provider_created_at);
        $this->assertArrayNotHasKey('payment_number', $payment->provider_payload['payment']);
        $this->assertArrayNotHasKey('api_key', $payment->provider_payload['payment']);
        $this->assertSame('provider metadata is safe', $payment->provider_payload['payment']['provider_context']['safe_note']);
        $this->assertArrayNotHasKey('x-api-key', $payment->provider_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('apiKey', $payment->provider_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('authorization', $payment->provider_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('signature', $payment->provider_payload['payment']['provider_context']);

        $rawStoredPaymentNumber = DB::table('payments')->where('id', $payment->id)->value('provider_payment_number');
        $this->assertIsString($rawStoredPaymentNumber);
        $this->assertNotSame($paymentNumber, $rawStoredPaymentNumber);
        $storedPayloadJson = json_encode($payment->provider_payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($paymentNumber, $storedPayloadJson);
        foreach ($providerSecretValues as $providerSecretValue) {
            $this->assertStringNotContainsString($providerSecretValue, $storedPayloadJson);
        }

        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::ProviderCreate, $event->event_type);
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $eventPayloadJson = json_encode($event->raw_payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($paymentNumber, $eventPayloadJson);
        foreach ($providerSecretValues as $providerSecretValue) {
            $this->assertStringNotContainsString($providerSecretValue, $eventPayloadJson);
        }
        $this->assertArrayNotHasKey('payment_number', $event->raw_payload['payment']);
        $this->assertArrayNotHasKey('api_key', $event->raw_payload['payment']);
        $this->assertSame('provider metadata is safe', $event->raw_payload['payment']['provider_context']['safe_note']);
        $this->assertArrayNotHasKey('x-api-key', $event->raw_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('apiKey', $event->raw_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('authorization', $event->raw_payload['payment']['provider_context']);
        $this->assertArrayNotHasKey('signature', $event->raw_payload['payment']['provider_context']);
        Http::assertNothingSent();
    }

    public function test_native_qris_provider_expiry_tightens_local_order_expiry(): void
    {
        $providerExpiry = now()->addMinutes(15)->startOfSecond();
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        Http::fake(fn ($request) => Http::response([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $request['order_id'],
                'amount' => $request['amount'],
                'fee' => 380,
                'total_payment' => $request['amount'] + 380,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => $providerExpiry->utc()->toAtomString(),
            ],
        ], 200));
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::HOSTED, now()->addHour()));
        $payment = $order->payments()->firstOrFail();

        app(PakasirNativeQrisService::class)->ensureCreated($payment);

        $this->assertTrue($payment->fresh()->expired_at?->equalTo($providerExpiry));
        $this->assertTrue($order->fresh()->expires_at?->equalTo($providerExpiry));
    }

    public function test_native_qris_service_returns_persisted_transaction_without_duplicate_provider_create(): void
    {
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        Http::fake([
            'https://app.pakasir.com/api/transactioncreate/qris' => fn ($request) => Http::response([
                'payment' => [
                    'project' => 'warung-ai',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 380,
                    'total_payment' => $request['amount'] + 380,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => now()->addMinutes(15)->utc()->toAtomString(),
                ],
            ], 200),
        ]);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $service = app(PakasirNativeQrisService::class);

        $first = $service->ensureCreated($payment);
        $second = $service->ensureCreated($payment->fresh());

        $this->assertSame($paymentNumber, $first->paymentNumber());
        $this->assertSame($paymentNumber, $second->paymentNumber());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === 'https://app.pakasir.com/api/transactioncreate/qris'));
        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCreate)->count());
    }

    public function test_native_qris_uncertain_create_is_recovered_on_the_same_payment(): void
    {
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        Http::fakeSequence()
            ->push(['message' => 'provider unavailable'], 500)
            ->push([
                'transaction' => [
                    'project' => 'warung-ai',
                    'order_id' => $payment->merchant_ref,
                    'amount' => $payment->amount,
                    'fee' => 380,
                    'total_payment' => 10380,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'status' => 'pending',
                    'expired_at' => now()->addMinutes(15)->utc()->toAtomString(),
                ],
            ], 200);

        $service = app(PakasirNativeQrisService::class);
        try {
            $service->ensureCreated($payment);
            $this->fail('Expected the first provider response to be uncertain.');
        } catch (RuntimeException) {
            $this->assertSame('outcome_unknown', $payment->fresh()->provider_create_state);
            $this->assertSame(1, Payment::query()->count());
        }

        $transaction = $service->ensureCreated($payment->fresh());

        $this->assertSame($paymentNumber, $transaction->paymentNumber());
        $this->assertSame('created', $payment->fresh()->provider_create_state);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::ProviderCreate)->count());
        Http::assertSentCount(2);
    }

    public function test_native_qris_service_does_not_call_provider_for_cancelled_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $order->update(['order_status' => OrderStatus::Cancelled]);
        Http::fake();

        try {
            app(PakasirNativeQrisService::class)->ensureCreated($payment->fresh());
            $this->fail('Expected cancelled Order to reject Native QRIS creation.');
        } catch (RuntimeException) {
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
            $this->assertNull($payment->fresh()->provider_payment_number);
            Http::assertNothingSent();
        }
    }

    public function test_native_qris_service_does_not_persist_provider_response_if_order_becomes_terminal_during_create(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();
        $paymentNumber = QrisTestPayload::forAmount($payment->amount + 380);
        Http::fake([
            'https://app.pakasir.com/api/transactioncreate/qris' => function ($request) use ($order, $payment, $paymentNumber) {
                $order->update(['order_status' => OrderStatus::Cancelled]);

                return Http::response([
                    'payment' => [
                        'project' => 'warung-ai',
                        'order_id' => $request['order_id'],
                        'amount' => $payment->amount,
                        'fee' => 380,
                        'total_payment' => $payment->amount + 380,
                        'payment_method' => 'qris',
                        'payment_number' => $paymentNumber,
                        'expired_at' => '2026-07-04T16:30:00Z',
                    ],
                ], 200);
            },
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(PakasirNativeQrisService::class)->ensureCreated($payment);
        } finally {
            $fresh = $payment->fresh();
            $this->assertNull($fresh->provider_payload);
            $this->assertNull($fresh->provider_payment_method);
            $this->assertSame('outcome_unknown', $fresh->provider_create_state);
            $this->assertSame(0, $fresh->paymentEvents()->count());
            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
            Http::assertSentCount(1);
        }
    }

    public function test_native_qris_service_does_not_expose_an_expired_provider_response(): void
    {
        $paymentNumber = QrisTestPayload::forAmount(10_380);
        Http::fake(fn ($request) => Http::response([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $request['order_id'],
                'amount' => $request['amount'],
                'fee' => 380,
                'total_payment' => $request['amount'] + 380,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => now()->subMinute()->utc()->toAtomString(),
            ],
        ], 200));
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
        $payment = $order->payments()->firstOrFail();

        try {
            app(PakasirNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected an expired provider response to remain non-payable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Pakasir native QRIS has expired.', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertSame('provider_expired', $fresh->provider_create_state);
        $this->assertFalse($fresh->isPayableNativePakasirQris());
        $this->assertTrue($fresh->provider_expires_at?->isPast());
    }

    public function test_qris_transaction_create_fails_closed_for_mismatched_response_or_missing_payment_number(): void
    {
        $cases = [
            'project mismatch' => fn (array $payment): array => array_replace($payment, ['project' => 'wrong-project']),
            'order mismatch' => fn (array $payment): array => array_replace($payment, ['order_id' => $payment['order_id'].'-wrong']),
            'amount mismatch' => fn (array $payment): array => array_replace($payment, ['amount' => $payment['amount'] + 1]),
            'missing payment number' => function (array $payment): array {
                unset($payment['payment_number']);

                return $payment;
            },
        ];

        foreach ($cases as $case => $mutate) {
            $paymentNumber = QrisTestPayload::forAmount(10_380);
            Http::fake(fn ($request) => Http::response([
                'payment' => $mutate([
                    'project' => 'warung-ai',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 380,
                    'total_payment' => $request['amount'] + 380,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => '2026-07-04T16:30:00Z',
                ]),
            ], 200));
            $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir());
            $payment = $order->payments()->firstOrFail();

            try {
                app(PakasirClient::class)->createQrisTransaction($payment);
                $this->fail('Expected Pakasir QRIS create to fail closed for '.$case.'.');
            } catch (\UnexpectedValueException $e) {
                $this->assertStringContainsString('Pakasir QRIS create response', $e->getMessage());
            }

            $payment->refresh();
            $this->assertNull($payment->provider_payload, $case);
            $this->assertNull($payment->provider_fee, $case);
            $this->assertNull($payment->provider_total_payment, $case);
            $this->assertNull($payment->provider_payment_method, $case);
            $this->assertNull($payment->provider_payment_number, $case);
            $this->assertNull($payment->provider_payment_number_hash, $case);
            $this->assertNull($payment->provider_created_at, $case);
            $this->assertNull($payment->provider_expires_at, $case);
        }

        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_production_validation_allows_missing_pakasir_credentials_until_dashboard_enables_pakasir(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.pakasir.slug' => '',
            'services.pakasir.api_key' => '',
        ]);
        app()->detectEnvironment(fn () => 'production');

        (new AppServiceProvider(app()))->boot();

        $this->assertTrue(true);
    }

    public function test_production_validation_requires_https_pakasir_base_url_when_configured(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-secret',
            'services.pakasir.base_url' => 'http://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAKASIR_BASE_URL must use HTTPS in production.');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_manual_checkout_remains_local_and_does_not_call_pakasir(): void
    {
        Http::fake();
        config(['services.telegram.payment_mode' => 'manual']);

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->firstOrFail();

        $this->assertSame('manual', $payment->provider);
        $this->assertSame('QR_MANUAL', $payment->method);
        $this->assertNull($payment->provider_reference);
        Http::assertNothingSent();
    }

    private function cartWithProduct(int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['first_name' => 'Buyer', 'phone_number' => '08123456789']);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => 10000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
