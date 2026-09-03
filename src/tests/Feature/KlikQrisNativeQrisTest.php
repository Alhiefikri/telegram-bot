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
use App\Models\Product;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCreatePreRequestException;
use App\Services\KlikQris\KlikQrisNativeQrisLifecycleResult;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\OrderCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisNativeQrisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->travelTo('2026-07-28 07:00:00');
    }

    private function lifecycle(Payment $payment): KlikQrisNativeQrisLifecycleResult
    {
        return app(KlikQrisNativeQrisService::class)->lifecycle($payment);
    }

    public function test_customer_status_refresh_requires_create_evidence_but_not_provider_total_or_method(): void
    {
        $unknown = KlikQrisSandboxScenarios::klikQrisPayment([
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => 'outcome_unknown',
        ]);
        $notSubmitted = KlikQrisSandboxScenarios::klikQrisPayment([
            'merchant_ref' => 'INV/ORDER-NOT-SUBMITTED',
            'provider_payment_method' => null,
            'provider_total_payment' => null,
            'provider_payload' => null,
            'provider_create_state' => null,
        ]);

        $service = app(KlikQrisNativeQrisService::class);

        $this->assertTrue($service->isCustomerStatusRefreshable($unknown));
        $this->assertFalse($service->isCustomerStatusRefreshable($notSubmitted));
    }

    public function test_lifecycle_creates_persists_sanitized_payable_and_shortens_deadline(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(price: 1000), CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'qris_image' => 'raw-base64-image',
            'total_amount' => '1016.0',
            'raw_payload' => 'RAW_PROVIDER_PAYLOAD',
            'api_key' => 'PROVIDER_API_KEY',
            'id_merchant' => 'PROVIDER_MERCHANT_ID',
            'nested' => ['client_secret' => 'PROVIDER_CLIENT_SECRET'],
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));

        $result = $this->lifecycle($payment);
        $payable = $result->payable();

        $fresh = $payment->fresh();
        $persistedPayload = json_encode($fresh->provider_payload, JSON_THROW_ON_ERROR);
        $this->assertTrue($result->isPayable());
        $this->assertSame(KlikQrisNativeQrisOutcome::Payable, $result->outcome());
        $this->assertNotNull($payable);
        $this->assertSame($fresh->klikQrisQrisUrl(), $payable->qrisUrl());
        $this->assertSame(1016, $payable->totalAmount());
        $this->assertSame('IDR 1016.0', $payable->totalAmountDisplay());
        $this->assertTrue($payable->effectiveExpiresAt()->equalTo($fresh->provider_expires_at));
        $this->assertSame('created', $fresh->provider_create_state);
        $this->assertSame(1016, $fresh->provider_total_payment);
        $this->assertSame(16, $fresh->provider_fee);
        $this->assertSame('qris', $fresh->provider_payment_method);
        $this->assertNotNull($fresh->provider_expires_at);
        $this->assertTrue($fresh->isPayableNativeKlikQris());
        $this->assertSame('1016.0', $fresh->provider_payload['data']['total_amount']);
        $this->assertArrayNotHasKey('signature', $fresh->provider_payload['data']);
        $this->assertArrayNotHasKey('qris_image', $fresh->provider_payload['data']);
        foreach ([
            'raw-base64-image',
            'opaque-test-signature',
            'RAW_PROVIDER_PAYLOAD',
            'PROVIDER_API_KEY',
            'PROVIDER_MERCHANT_ID',
            'PROVIDER_CLIENT_SECRET',
        ] as $deniedValue) {
            $this->assertStringNotContainsString($deniedValue, $persistedPayload);
        }
        $this->assertSame($fresh->provider_expires_at->timestamp, $fresh->expired_at->timestamp);
        $this->assertSame($fresh->provider_expires_at->timestamp, $fresh->order->expires_at->timestamp);
        $this->assertSame($fresh->provider_expires_at->timestamp, $payable->effectiveExpiresAt()->timestamp);
        $this->assertSame(1, $fresh->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
        $this->assertArrayNotHasKey('signature', $fresh->paymentEvents()->first()->raw_payload['data']);
        Http::assertSentCount(1);
    }

    public function test_create_signature_is_encrypted_hidden_and_absent_from_generic_payloads(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        $this->lifecycle($payment);

        $fresh = $payment->fresh();
        $this->assertSame('opaque-test-signature', $fresh->klikqris_transaction_signature);
        $this->assertNotSame(
            'opaque-test-signature',
            DB::table('payments')->where('id', $payment->id)->value('klikqris_transaction_signature'),
        );
        $this->assertArrayNotHasKey('klikqris_transaction_signature', $fresh->toArray());
        $this->assertStringNotContainsString(
            'opaque-test-signature',
            json_encode($fresh->provider_payload, JSON_THROW_ON_ERROR),
        );
    }

    public function test_lifecycle_does_not_call_provider_for_cancelled_order(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        $payment->order->update(['order_status' => OrderStatus::Cancelled]);
        Http::fake();

        $result = $this->lifecycle($payment->fresh());

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        $this->assertNull($result->payable());
        Http::assertNothingSent();
    }

    public function test_rollout_modes_select_the_hardened_lifecycle_path_and_rollback_stops_new_selection(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000, 'merchant_ref' => 'KLIK-SHADOW']);
        $payment->order->update(['order_status' => OrderStatus::Cancelled]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        config()->set('services.payment_lifecycle_rollout.mode', 'shadow');
        $this->lifecycle($payment->fresh());
        Http::assertSentCount(1);

        $hardened = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000, 'merchant_ref' => 'KLIK-ENABLED']);
        $hardened->order->update(['order_status' => OrderStatus::Cancelled]);
        config()->set('services.payment_lifecycle_rollout.mode', 'enabled');
        $result = $this->lifecycle($hardened->fresh());

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        Http::assertSentCount(1);

        config()->set('services.payment_lifecycle_rollout.mode', 'rollback');
        $rollback = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000, 'merchant_ref' => 'KLIK-ROLLBACK']);
        $rollback->order->update(['order_status' => OrderStatus::Cancelled]);
        $this->lifecycle($rollback->fresh());
        Http::assertSentCount(2);
    }

    public function test_lifecycle_does_not_persist_provider_response_if_order_becomes_terminal_during_create(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        $order = $payment->order;
        Http::fake([
            'https://klikqris.com/api/sandbox/qris/create' => function () use ($order, $payment) {
                $order->update(['order_status' => OrderStatus::Cancelled]);

                return Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200);
            },
        ]);

        $result = $this->lifecycle($payment);
        $fresh = $payment->fresh();

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertNull($fresh->provider_payload);
        $this->assertNull($fresh->provider_total_payment);
        $this->assertNull($fresh->provider_created_at);
        $this->assertNull($fresh->provider_expires_at);
        $this->assertSame('outcome_unknown', $fresh->provider_create_state);
        $this->assertSame(0, $fresh->paymentEvents()->count());
        Http::assertSentCount(1);
    }

    public function test_base64_only_create_returns_provider_total_pending_without_exposing_or_persisting_media(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        $base64 = KlikQrisSandboxScenarios::pngDataUri('native-base64-only');
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'qris_url' => null,
            'qris_image' => $base64,
            'id_merchant' => 'MERCHANT_SECRET_CREATE',
            'nested' => ['merchant_id' => 'MERCHANT_SECRET_NESTED'],
        ]), 200));

        $result = $this->lifecycle($payment);
        $fresh = $payment->fresh();
        $persistedJson = json_encode($fresh->provider_payload, JSON_THROW_ON_ERROR);

        $this->assertSame(KlikQrisNativeQrisOutcome::ProviderTotalPending, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame('transient_qris_image', $fresh->provider_create_state);
        $this->assertSame(1016, $fresh->provider_total_payment);
        $this->assertFalse($fresh->isPayableNativeKlikQris());
        $this->assertArrayNotHasKey('qris_image', $fresh->provider_payload['data']);
        $this->assertStringNotContainsString($base64, $persistedJson);
        $this->assertStringNotContainsString('MERCHANT_SECRET_CREATE', $persistedJson);
        $this->assertStringNotContainsString('MERCHANT_SECRET_NESTED', $persistedJson);
        $this->assertSame(0, $fresh->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    public function test_base64_only_retry_uses_status_recovery_without_reposting_or_leaking_base64(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        $base64 = KlikQrisSandboxScenarios::pngDataUri('retry-base64-only');
        Http::fakeSequence()
            ->push(KlikQrisSandboxScenarios::createPayload($payment, [
                'qris_url' => null,
                'qris_image' => $base64,
            ]), 200)
            ->push(KlikQrisSandboxScenarios::statusPayload($payment, [
                'status' => 'PENDING',
                'qris_url' => null,
                'qris_image' => $base64,
            ]), 200);

        $first = $this->lifecycle($payment);
        $this->assertSame(KlikQrisNativeQrisOutcome::ProviderTotalPending, $first->outcome());

        $second = $this->lifecycle($payment->fresh());

        $this->assertSame(KlikQrisNativeQrisOutcome::InvalidProviderResult, $second->outcome());
        $this->assertNull($second->payable());
        Http::assertSentCount(2);
        $this->assertSame('provider_qris_unusable', $payment->fresh()->provider_create_state);
        $this->assertStringNotContainsString($base64, json_encode($payment->fresh()->provider_payload, JSON_THROW_ON_ERROR));
    }

    public function test_sequential_calls_return_same_payable_without_second_create_request(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        $first = $this->lifecycle($payment);
        $second = $this->lifecycle($payment->fresh());

        $this->assertTrue($first->isPayable());
        $this->assertTrue($second->isPayable());
        $this->assertSame($first->payable()->qrisUrl(), $second->payable()->qrisUrl());
        $this->assertSame($first->payable()->totalAmount(), $second->payable()->totalAmount());
        Http::assertSentCount(1);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    public function test_paid_payment_reuses_persisted_qris_without_another_provider_request(): void
    {
        $expiresAt = now()->addMinutes(10);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'status' => PaymentStatus::Paid,
            'provider_total_payment' => 1016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => $expiresAt,
            'provider_create_state' => 'created',
        ]);
        $payment->order->update([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $payment->update([
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment->fresh(), [
                'expired_at' => $expiresAt->toISOString(),
            ]),
        ]);

        Http::fake();

        $result = $this->lifecycle($payment->fresh());

        $this->assertTrue($result->isPayable());
        $this->assertSame(1016, $result->payable()->totalAmount());
        $this->assertSame('https://klikqris.com/storage/sandbox/qris_sandbox_transaction.png', $result->payable()->qrisUrl());
        Http::assertNothingSent();
    }

    public function test_uncertain_create_failure_returns_recovery_pending_and_recovers_on_same_payment(): void
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

        $first = $this->lifecycle($payment);

        $this->assertSame(KlikQrisNativeQrisOutcome::RecoveryPending, $first->outcome());
        $this->assertNull($first->payable());
        $failed = $payment->fresh();
        $this->assertSame('outcome_unknown', $failed->provider_create_state);
        $this->assertSame(ConnectionException::class, $failed->provider_create_last_error);
        $this->assertSame('klikqris', strtolower((string) $failed->provider));
        $this->assertSame(1, Payment::query()->count());

        $recovered = $this->lifecycle($payment->fresh());

        $this->assertTrue($recovered->isPayable());
        $this->assertSame('https://klikqris.com/storage/sandbox/qris_recovered.png', $recovered->payable()->qrisUrl());
        $this->assertSame('created', $payment->fresh()->provider_create_state);
        $this->assertCount(2, $calls);
        $this->assertSame('POST https://klikqris.com/api/sandbox/qris/create', $calls[0]);
        $this->assertStringStartsWith('GET https://klikqris.com/api/sandbox/qris/status/', $calls[1]);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
    }

    public function test_invalid_final_amount_is_invalid_provider_result_and_never_payable(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'total_amount' => 999,
        ]), 200));

        $result = $this->lifecycle($payment);

        $this->assertSame(KlikQrisNativeQrisOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertFalse($payment->fresh()->isPayableNativeKlikQris());
        $this->assertSame('provider_total_invalid', $payment->fresh()->provider_create_state);
    }

    public function test_create_response_validation_failure_after_http_is_recovery_pending(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'order_id' => 'wrong-order',
        ]), 200));

        $result = $this->lifecycle($payment);

        $this->assertSame(KlikQrisNativeQrisOutcome::RecoveryPending, $result->outcome());
        $this->assertSame('outcome_unknown', $payment->fresh()->provider_create_state);
    }

    public function test_http_create_failure_after_response_is_recovery_pending_without_secret_leak(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(['message' => 'secret body opaque-test-signature'], 422));

        $result = $this->lifecycle($payment);

        $this->assertSame(KlikQrisNativeQrisOutcome::RecoveryPending, $result->outcome());
        $fresh = $payment->fresh();
        $this->assertSame('outcome_unknown', $fresh->provider_create_state);
        $this->assertStringNotContainsString('opaque-test-signature', (string) $fresh->provider_create_last_error);
    }

    public function test_readiness_failure_before_request_throws_and_marks_create_failed_without_http(): void
    {
        config()->set('services.klikqris.sandbox_api_key', null);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake();

        try {
            $this->lifecycle($payment);
            $this->fail('Expected readiness failure.');
        } catch (KlikQrisCreatePreRequestException) {
            $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        }

        Http::assertNothingSent();
    }

    public function test_readiness_failure_can_retry_create_after_configuration_recovers(): void
    {
        config()->set('services.klikqris.sandbox_api_key', null);
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment), 200));

        try {
            $this->lifecycle($payment);
            $this->fail('Expected readiness failure.');
        } catch (KlikQrisCreatePreRequestException) {
            $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        }

        config()->set('services.klikqris.sandbox_api_key', 'test-only-key');
        $result = $this->lifecycle($payment->fresh());

        $this->assertTrue($result->isPayable());
        $this->assertSame('created', $payment->fresh()->provider_create_state);
        Http::assertSentCount(1);
    }

    public function test_provider_expiry_later_than_local_deadline_keeps_earlier_order_deadline(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'expired_at' => now()->addMinutes(5),
        ]);
        $payment->order->update(['expires_at' => now()->addMinutes(5)]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));

        $result = $this->lifecycle($payment);

        $this->assertTrue($result->isPayable());
        $this->assertSame(now()->addMinutes(5)->timestamp, $result->payable()->effectiveExpiresAt()->timestamp);
        $this->assertSame(now()->addMinutes(5)->timestamp, $payment->fresh()->expired_at->timestamp);
        $this->assertSame(now()->addMinutes(5)->timestamp, $payment->fresh()->order->expires_at->timestamp);
    }

    public function test_expire_pending_orders_releases_reservation_and_lifecycle_returns_expired_after_provider_deadline(): void
    {
        config()->set('services.telegram.token', null);
        $startedAt = now()->startOfMinute();
        $this->travelTo($startedAt);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(price: 1000, stock: 1), CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => $startedAt->copy()->addMinutes(15)->toAtomString(),
        ]), 200));

        $this->assertTrue($this->lifecycle($payment)->isPayable());
        $this->assertTrue($payment->fresh()->isPayableNativeKlikQris());

        $this->travelTo($startedAt->copy()->addMinutes(16));
        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('orders:expire-pending completed cleanly: processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $product = Product::firstOrFail();
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);

        $result = $this->lifecycle($payment->fresh());

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertFalse($payment->fresh()->isPayableNativeKlikQris());
    }

    public function test_payment_with_past_provider_deadline_returns_expired_without_provider_requests(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'provider_total_payment' => 1016,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => now()->subMinute(),
            'provider_create_state' => 'created',
        ]);
        $payment->update([
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment->fresh(), [
                'expired_at' => now()->subMinute()->toAtomString(),
            ]),
        ]);
        Http::fake();

        $result = $this->lifecycle($payment->fresh());

        $this->assertSame(KlikQrisNativeQrisOutcome::Expired, $result->outcome());
        $this->assertNull($result->payable());
        Http::assertNothingSent();
    }

    #[DataProvider('tamperedPersistedPaymentProvider')]
    public function test_tampered_incomplete_or_untrusted_persisted_payload_never_becomes_payable(callable $tamper): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'expired_at' => now()->addMinutes(10)->toAtomString(),
        ]), 200));
        $this->assertTrue($this->lifecycle($payment)->isPayable());

        $tamper($payment->fresh());
        // Hard-reset fakes: Laravel merges Http::fake() stubs, so the original
        // create stub would otherwise keep answering. Stray requests then
        // throw instead of touching the network.
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $result = $this->lifecycle($payment->fresh());

        $this->assertNotSame(KlikQrisNativeQrisOutcome::Payable, $result->outcome());
        $this->assertNull($result->payable());
        foreach (Http::recorded() as [$request]) {
            $this->assertNotSame('POST', $request->method(), 'Tampered persisted data must never trigger a provider create.');
        }
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
        ];
    }

    public function test_recovery_non_pending_or_missing_media_returns_invalid_provider_result_without_create_events(): void
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

        $outcomes = [];
        for ($i = 0; $i < 2; $i++) {
            $outcomes[] = $this->lifecycle($payment->fresh())->outcome();
        }

        $this->assertSame([KlikQrisNativeQrisOutcome::InvalidProviderResult, KlikQrisNativeQrisOutcome::InvalidProviderResult], $outcomes);
        $fresh = $payment->fresh();
        $this->assertSame('provider_SUCCESS', $fresh->provider_create_state);
        $this->assertSame(0, $fresh->paymentEvents()->where('event_type', PaymentEventType::ProviderCreate->value)->count());
        Http::assertSentCount(2);
    }

    public function test_creating_state_uses_status_only_and_returns_recovery_pending_on_unavailable_status(): void
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
            $result = $this->lifecycle($payment->fresh());
            $this->assertSame(KlikQrisNativeQrisOutcome::RecoveryPending, $result->outcome());
        }

        $this->assertSame(['GET', 'GET'], $methods);
        $this->assertSame('recovery_unconfirmed', $payment->fresh()->provider_create_state);
    }

    public function test_uncertain_create_never_switches_provider_or_creates_another_payment(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(function () {
            throw new ConnectionException('socket reset after send');
        });

        $this->assertSame(KlikQrisNativeQrisOutcome::RecoveryPending, $this->lifecycle($payment)->outcome());

        $fresh = $payment->fresh();
        $this->assertSame('klikqris', strtolower((string) $fresh->provider));
        $this->assertNull($fresh->provider_expires_at);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, Payment::query()->whereNot('id', $payment->id)->count());
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
