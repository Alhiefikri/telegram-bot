<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisNativeQrisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klikqris', KlikQrisSandboxScenarios::readyConfig());
        $this->travelTo('2026-07-28 07:00:00');
    }

    public function test_ensure_created_persists_sanitized_payable_transaction_and_shortens_deadline(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(price: 1000), CheckoutPaymentIntents::klikQris(now()->addMinutes(30)));
        $payment = $order->payments()->sole();

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'qris_image' => 'raw-base64-image',
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));

        $transaction = app(KlikQrisNativeQrisService::class)->ensureCreated($payment);

        $fresh = $payment->fresh();
        $this->assertSame($payment->merchant_ref, $transaction->orderId());
        $this->assertSame('created', $fresh->provider_create_state);
        $this->assertSame(1016, $fresh->provider_total_payment);
        $this->assertSame(16, $fresh->provider_fee);
        $this->assertSame('qris', $fresh->provider_payment_method);
        $this->assertSame('opaque-test-signature', $fresh->provider_transaction_token);
        $this->assertNotNull($fresh->provider_expires_at);
        $this->assertTrue($fresh->isPayableNativeKlikQris());
        $this->assertArrayNotHasKey('signature', $fresh->provider_payload['data']);
        $this->assertArrayNotHasKey('qris_image', $fresh->provider_payload['data']);
        $this->assertSame($fresh->provider_expires_at->timestamp, $fresh->expired_at->timestamp);
        $this->assertSame($fresh->provider_expires_at->timestamp, $fresh->order->expires_at->timestamp);
        $this->assertSame(1, $fresh->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
        $this->assertArrayNotHasKey('signature', $fresh->paymentEvents()->first()->raw_payload['data']);
        Http::assertSentCount(1);
    }

    public function test_sequential_calls_return_persisted_payment_without_second_create_request(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
        $again = app(KlikQrisNativeQrisService::class)->ensureCreated($payment->fresh());

        $this->assertSame($payment->merchant_ref, $again->orderId());
        Http::assertSentCount(1);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    public function test_uncertain_create_failure_is_recovered_by_status_without_blind_second_create(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        $calls = [];
        Http::fake(function ($request) use (&$calls, $payment) {
            $calls[] = $request->method().' '.$request->url();

            if ($request->method() === 'POST') {
                throw new ConnectionException('socket reset after send');
            }

            return Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_recovered.png',
                'expired_at' => now()->addMinutes(10)->toAtomString(),
            ]), 200);
        });

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected uncertain create failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('QRIS sedang dipulihkan', $exception->getMessage());
        }

        $failed = $payment->fresh();
        $this->assertSame('outcome_unknown', $failed->provider_create_state);
        $this->assertSame(ConnectionException::class, $failed->provider_create_last_error);

        $recovered = app(KlikQrisNativeQrisService::class)->ensureCreated($payment->fresh());

        $this->assertSame('created', $payment->fresh()->provider_create_state);
        $this->assertSame('https://klikqris.com/storage/sandbox/qris_recovered.png', $recovered->qrisUrl());
        $this->assertCount(2, $calls);
        $this->assertSame('POST https://klikqris.com/api/sandbox/qris/create', $calls[0]);
        $this->assertStringStartsWith('GET https://klikqris.com/api/sandbox/qris/status/', $calls[1]);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    public function test_invalid_final_amount_remains_non_payable(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'total_amount' => 999,
        ]), 200));

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected invalid provider final amount to fail.');
        } catch (RuntimeException) {
            $this->assertFalse($payment->fresh()->isPayableNativeKlikQris());
        }
    }

    public function test_create_response_validation_failure_after_http_is_outcome_unknown(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'order_id' => 'wrong-order',
        ]), 200));

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected definitive validation failure.');
        } catch (RuntimeException) {
            $this->assertSame('outcome_unknown', $payment->fresh()->provider_create_state);
        }
    }

    public function test_http_create_failure_after_response_is_outcome_unknown_without_secret_leak(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(['message' => 'secret body opaque-test-signature'], 422));

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected uncertain HTTP response failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('opaque-test-signature', $exception->getMessage());
        }

        $fresh = $payment->fresh();
        $this->assertSame('outcome_unknown', $fresh->provider_create_state);
        $this->assertStringNotContainsString('opaque-test-signature', (string) $fresh->provider_create_last_error);
    }

    public function test_readiness_failure_before_request_is_create_failed_without_http(): void
    {
        config()->set('services.klikqris.sandbox_api_key', null);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake();

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected readiness failure.');
        } catch (RuntimeException) {
            $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        }

        Http::assertNothingSent();
    }

    public function test_provider_expiry_later_than_local_deadline_does_not_extend_order(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'expired_at' => now()->addMinutes(5),
        ]);
        $payment->order->update(['expires_at' => now()->addMinutes(5)]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));

        app(KlikQrisNativeQrisService::class)->ensureCreated($payment);

        $this->assertSame(now()->addMinutes(5)->timestamp, $payment->fresh()->expired_at->timestamp);
        $this->assertSame(now()->addMinutes(5)->timestamp, $payment->fresh()->order->expires_at->timestamp);
    }

    public function test_expire_pending_orders_releases_klikqris_reservation_and_makes_old_qr_unpayable_after_provider_deadline(): void
    {
        config()->set('services.telegram.token', null);
        $startedAt = now()->startOfMinute();
        $this->travelTo($startedAt);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(price: 1000, stock: 1), CheckoutPaymentIntents::klikQris($startedAt->copy()->addMinutes(30)));
        $payment = $order->payments()->sole();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => $startedAt->copy()->addMinutes(15)->toAtomString(),
        ]), 200));

        app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
        $this->assertTrue($payment->fresh()->isPayableNativeKlikQris());

        $this->travelTo($startedAt->copy()->addMinutes(16));
        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('orders:expire-pending completed cleanly: processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $product = Product::firstOrFail();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertFalse($payment->fresh()->isPayableNativeKlikQris());
    }

    public function test_from_persisted_payment_reconstructs_with_encrypted_token_and_fails_closed(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));
        app(KlikQrisNativeQrisService::class)->ensureCreated($payment);

        $transaction = KlikQrisTransaction::fromPersistedPayment($payment->fresh());

        $this->assertSame('opaque-test-signature', $transaction->signature());
        $this->assertSame($payment->merchant_ref, $transaction->orderId());

        $wrongProvider = $payment->fresh();
        $wrongProvider->provider = 'pakasir';
        $this->expectException(RuntimeException::class);
        KlikQrisTransaction::fromPersistedPayment($wrongProvider);
    }

    #[DataProvider('tamperedPersistedPaymentProvider')]
    public function test_from_persisted_payment_rejects_tampered_incomplete_or_untrusted_payload(callable $tamper): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));
        app(KlikQrisNativeQrisService::class)->ensureCreated($payment);

        $tamper($payment->fresh());

        $this->expectException(RuntimeException::class);
        KlikQrisTransaction::fromPersistedPayment($payment->fresh());
    }

    public static function tamperedPersistedPaymentProvider(): array
    {
        return [
            'missing order_id' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_diff_key($payment->provider_payload['data'], ['order_id' => true])]])],
            'mismatched amount' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_replace($payment->provider_payload['data'], ['amount' => $payment->amount + 1])]])],
            'mismatched total' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_replace($payment->provider_payload['data'], ['total_amount' => $payment->provider_total_payment + 1])]])],
            'missing status' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_diff_key($payment->provider_payload['data'], ['status' => true])]])],
            'untrusted qris url' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_replace($payment->provider_payload['data'], ['qris_url' => 'https://evil.test/qris'])]])],
            'non https qris url' => [fn ($payment) => $payment->update(['provider_payload' => ['data' => array_replace($payment->provider_payload['data'], ['qris_url' => 'http://klikqris.com/storage/sandbox/qris_bad.png'])]])],
            'missing encrypted token' => [fn ($payment) => $payment->update(['provider_transaction_token' => null])],
        ];
    }

    public function test_recovery_non_pending_or_missing_media_does_not_mark_created_or_duplicate_events(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_create_state' => 'outcome_unknown',
        ]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
            'status' => 'SUCCESS',
            'qris_url' => null,
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));

        for ($i = 0; $i < 2; $i++) {
            try {
                app(KlikQrisNativeQrisService::class)->ensureCreated($payment->fresh());
            } catch (RuntimeException) {
                // Expected fail-closed recovery.
            }
        }

        $fresh = $payment->fresh();
        $this->assertNotSame('created', $fresh->provider_create_state);
        $this->assertSame(0, $fresh->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
        Http::assertSentCount(2);
    }

    public function test_creating_state_with_status_404_never_posts_create_on_repeated_calls(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_create_state' => 'creating',
        ]);
        $methods = [];
        Http::fake(function ($request) use (&$methods) {
            $methods[] = $request->method();

            return Http::response(['message' => 'not found'], 404);
        });

        for ($i = 0; $i < 2; $i++) {
            try {
                app(KlikQrisNativeQrisService::class)->ensureCreated($payment->fresh());
            } catch (RuntimeException) {
                // Expected status-only fail closed.
            }
        }

        $this->assertSame(['GET', 'GET'], $methods);
        $this->assertSame('recovery_unconfirmed', $payment->fresh()->provider_create_state);
    }

    private function cartWithProduct(int $price = 1000, int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(100000, 999999999)]);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => $price]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
