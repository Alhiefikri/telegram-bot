<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\PaymentEventType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Providers\AppServiceProvider;
use App\Services\OrderCheckoutService;
use App\Services\Pakasir\PakasirClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame(
            'https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref.'&redirect=https%3A%2F%2Fexample.test%2Fpayment%2Freturn&qris_only=1',
            $url,
        );
        $this->assertStringNotContainsString('api_key', $url);
    }

    public function test_hosted_payment_url_omits_optional_params_when_not_configured(): void
    {
        config()->set('services.pakasir.redirect_url', null);
        config()->set('services.pakasir.qris_only', false);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $url = app(PakasirClient::class)->hostedPaymentUrl($payment);

        $this->assertSame('https://app.pakasir.com/pay/warung-ai/10000?order_id='.$payment->merchant_ref, $url);
    }

    public function test_transaction_detail_request_uses_project_amount_order_id_and_api_key(): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response(['transaction' => ['status' => 'completed']], 200),
        ]);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $response = app(PakasirClient::class)->transactionDetail($payment);

        $this->assertSame(['transaction' => ['status' => 'completed']], $response);
        Http::assertSent(fn ($request) => $request->url() === 'https://app.pakasir.com/api/transactiondetail?project=warung-ai&amount=10000&order_id='.$payment->merchant_ref.'&api_key=pakasir-secret');
    }

    public function test_creates_qris_transaction_posts_to_pakasir_and_persists_sanitized_native_fields(): void
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
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
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
        $this->assertSame(103, $payment->provider_fee);
        $this->assertSame(10103, $payment->provider_total_payment);
        $this->assertSame('qris', $payment->provider_payment_method);
        $this->assertSame($paymentNumber, $payment->provider_payment_number);
        $this->assertSame(hash('sha256', $paymentNumber), $payment->provider_payment_number_hash);
        $this->assertSame('2026-07-04T16:30:00+00:00', $payment->provider_expires_at?->utc()->toAtomString());
        $this->assertNotNull($payment->provider_created_at);
        $this->assertArrayNotHasKey('payment_number', $payment->provider_payload['payment']);
        $this->assertArrayNotHasKey('api_key', $payment->provider_payload['payment']);

        $rawStoredPaymentNumber = DB::table('payments')->where('id', $payment->id)->value('provider_payment_number');
        $this->assertIsString($rawStoredPaymentNumber);
        $this->assertNotSame($paymentNumber, $rawStoredPaymentNumber);
        $this->assertStringNotContainsString($paymentNumber, json_encode($payment->provider_payload, JSON_THROW_ON_ERROR));
        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::ProviderCreate, $event->event_type);
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $this->assertStringNotContainsString($paymentNumber, json_encode($event->raw_payload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('payment_number', $event->raw_payload['payment']);
        $this->assertArrayNotHasKey('api_key', $event->raw_payload['payment']);
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
            $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
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

    public function test_production_validation_requires_slug_and_api_key_when_pakasir_mode_is_active(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
            'services.pakasir.slug' => '',
            'services.pakasir.api_key' => '',
        ]);
        app()->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required production configuration: PAKASIR_SLUG, PAKASIR_API_KEY');

        (new AppServiceProvider(app()))->boot();
    }

    public function test_production_validation_requires_https_pakasir_base_url_when_pakasir_mode_is_active(): void
    {
        config([
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
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

        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'QR_MANUAL', 'manual');
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
