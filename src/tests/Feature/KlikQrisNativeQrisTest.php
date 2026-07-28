<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

        if (! Schema::hasColumn('payments', 'provider_transaction_token')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->text('provider_transaction_token')->nullable();
            });
        }
    }

    public function test_ensure_created_persists_sanitized_payable_transaction_and_shortens_deadline(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 1000,
            'expired_at' => now()->addMinutes(30),
        ]);
        $payment->order->update(['expires_at' => now()->addMinutes(30)]);

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
                'qris_url' => 'https://klikqris.com/sandbox/qris/recovered',
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
        $this->assertSame('https://klikqris.com/sandbox/qris/recovered', $recovered->qrisUrl());
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

    public function test_definitive_create_validation_failure_does_not_mark_outcome_unknown(): void
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment(['amount' => 1000]);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::createPayload($payment, [
            'order_id' => 'wrong-order',
        ]), 200));

        try {
            app(KlikQrisNativeQrisService::class)->ensureCreated($payment);
            $this->fail('Expected definitive validation failure.');
        } catch (RuntimeException) {
            $this->assertSame('create_failed', $payment->fresh()->provider_create_state);
        }

        $this->assertNotSame('outcome_unknown', $payment->fresh()->provider_create_state);
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

    /** @group postgres */
    public function test_postgres_concurrent_ensure_created_observes_one_provider_create_effect(): void
    {
        $this->markTestSkipped('Requires the repository PostgreSQL multi-process harness; Task 5 report documents this blocker.');
    }
}
