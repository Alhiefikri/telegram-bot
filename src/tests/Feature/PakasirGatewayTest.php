<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
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
use RuntimeException;
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

    public function test_expire_pending_orders_releases_local_pakasir_order_without_provider_cancel(): void
    {
        config()->set('services.telegram.token', null);
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), CheckoutPaymentIntents::pakasir(PakasirCheckoutFlow::HOSTED, now()->subMinute()));
        $payment = $order->payments()->firstOrFail();

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('orders:expire-pending completed cleanly: processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        Http::assertNothingSent();
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
        $paymentNumber = '00020101021226610016ID.CO.QRIS.WWW0118SECRETQRSTRING';
        Http::fake(fn ($request) => Http::response([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $request['order_id'],
                'amount' => 10000,
                'fee' => 103,
                'total_payment' => 10103,
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
        $this->assertSame(103, $transaction->fee());
        $this->assertSame(10103, $transaction->totalPayment());
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
        $paymentNumber = '00020101021226610016ID.CO.QRIS.WWW0118SECRETQRSTRING';
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
                'fee' => 103,
                'total_payment' => 10103,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => '2026-07-04T16:30:00Z',
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
        $this->assertSame(103, $payment->provider_fee);
        $this->assertSame(10103, $payment->provider_total_payment);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertSame('2026-07-04T16:30:00+00:00', $payment->provider_expires_at?->utc()->toAtomString());
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
        $paymentNumber = '00020101021226610016ID.CO.QRIS.EXPIRY';
        Http::fake(fn ($request) => Http::response([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => $request['order_id'],
                'amount' => $request['amount'],
                'fee' => 103,
                'total_payment' => $request['amount'] + 103,
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
        $paymentNumber = '00020101021226610016ID.CO.QRIS.IDEMPOTENT92';
        Http::fake([
            'https://app.pakasir.com/api/transactioncreate/qris' => fn ($request) => Http::response([
                'payment' => [
                    'project' => 'warung-ai',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 103,
                    'total_payment' => $request['amount'] + 103,
                    'payment_method' => 'qris',
                    'payment_number' => $paymentNumber,
                    'expired_at' => '2026-07-04T16:30:00Z',
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
            $paymentNumber = '00020101021226610016ID.CO.QRIS.'.$case;
            Http::fake(fn ($request) => Http::response([
                'payment' => $mutate([
                    'project' => 'warung-ai',
                    'order_id' => $request['order_id'],
                    'amount' => $request['amount'],
                    'fee' => 103,
                    'total_payment' => $request['amount'] + 103,
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
