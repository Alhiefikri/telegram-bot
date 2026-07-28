<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.klikqris', KlikQrisSandboxScenarios::readyConfig());
        $this->travelTo('2026-07-28 07:05:00');
    }

    public function test_paid_callback_fulfills_only_after_authoritative_success_status_matches_payment_identity_and_amounts(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $response = $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment));

        $response->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNotNull($event->processed_at);
        Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->orderId === $payment->order_id);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Http::assertSentCount(1);
    }

    public function test_paid_callback_with_pending_authoritative_status_logs_unprocessed_event_without_fulfillment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment, ['status' => 'PENDING']), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNull($event->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertSentCount(1);
    }

    public function test_callback_amount_or_total_mismatch_is_acknowledged_without_status_call_or_fulfillment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake();

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['total_amount' => $payment->provider_total_payment + 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertNothingSent();
    }

    public function test_status_identity_or_amount_mismatch_is_acknowledged_without_fulfillment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment, ['total_amount' => $payment->provider_total_payment + 1]), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertSentCount(1);
    }

    public function test_unknown_order_and_non_paid_callbacks_are_acknowledged_and_never_call_status(): void
    {
        Http::fake();
        $payment = $this->payableKlikQrisPayment();

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['order_id' => 'unknown-ref']))
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['status' => 'PENDING']))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(2, PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->count());
        Http::assertNothingSent();
    }

    public function test_malformed_content_type_json_and_schema_are_rejected_without_status_or_events(): void
    {
        Http::fake();

        $this->post('/api/webhook/klikqris/sandbox', ['order_id' => 'x'])->assertStatus(400);
        $this->call('POST', '/api/webhook/klikqris/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad json')->assertStatus(400);
        $this->postJson('/api/webhook/klikqris/sandbox', ['order_id' => 'x', 'amount' => 1000])->assertStatus(422);

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_status_outage_after_stored_callback_returns_success_and_persists_reconciliation_needed_event_without_secrets(): void
    {
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => throw new ConnectionException('socket body secret-message opaque-test-signature'));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['nested' => ['signature' => 'nested-secret']]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $event = PaymentEvent::query()->where('provider', 'klikqris')->sole();
        $this->assertNull($event->processed_at);
        $this->assertSame('PAID', $event->provider_status);
        $this->assertArrayNotHasKey('signature', $event->raw_payload);
        $this->assertArrayNotHasKey('signature', $event->raw_payload['nested']);
        $this->assertStringNotContainsString('opaque-test-signature', json_encode($event->raw_payload));
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_duplicate_callbacks_with_different_signatures_coalesce_and_fulfill_once(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['signature' => 'first-signature']))->assertOk();
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['signature' => 'second-signature']))->assertOk();

        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertArrayNotHasKey('signature', $event->raw_payload);
        $this->assertNotNull($event->processed_at);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_rate_limit_uses_configured_klikqris_sandbox_limiter_without_changing_pakasir_route(): void
    {
        config()->set('services.klikqris.webhook_rate_limit_per_minute', 1);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))->assertOk();
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))->assertTooManyRequests();

        $this->assertNotNull(collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === 'api/webhook/pakasir'));
    }

    private function payableKlikQrisPayment()
    {
        $payment = KlikQrisSandboxScenarios::klikQrisPayment([
            'amount' => 10000,
            'provider_total_payment' => 10016,
            'provider_fee' => 16,
            'provider_expires_at' => now()->addMinutes(10),
            'expired_at' => now()->addMinutes(10),
        ]);
        $payment->update(['provider_payload' => KlikQrisSandboxScenarios::createPayload($payment)]);
        $payment->order->update(['expires_at' => now()->addMinutes(10)]);

        return $payment->fresh(['order']);
    }

    private function callbackPayload($payment, array $overrides = []): array
    {
        return array_replace([
            'order_id' => $payment->merchant_ref,
            'amount' => $payment->amount,
            'total_amount' => $payment->provider_total_payment,
            'status' => 'PAID',
            'signature' => 'opaque-callback-signature',
        ], $overrides);
    }
}
