<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\VerifyKlikQrisWebhookPayment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        KlikQrisSandboxScenarios::configureReadySandbox();
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

    public function test_sandbox_simulator_success_callback_triggers_authoritative_verification(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, ['status' => 'SUCCESS']))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull(PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
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

    public function test_callback_acknowledges_without_status_call_when_same_payment_is_being_verified(): void
    {
        $payment = $this->payableKlikQrisPayment();
        $lock = Cache::lock('klikqris:callback-status:'.$payment->getKey(), 30);
        $this->assertTrue($lock->get());
        Http::fake();

        try {
            $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))
                ->assertOk()
                ->assertExactJson(['success' => true]);
        } finally {
            $lock->release();
        }

        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Http::assertNothingSent();
    }

    public function test_paid_callback_with_unknown_local_total_still_runs_authoritative_verification(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        $payment->update([
            'provider_total_payment' => null,
            'provider_fee' => null,
            'provider_create_state' => 'outcome_unknown',
        ]);
        $payment->refresh();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
            'total_amount' => 10016,
        ]))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $event = PaymentEvent::query()
            ->where('provider', 'klikqris')
            ->where('event_type', PaymentEventType::Callback->value)
            ->sole();
        $this->assertNotNull($event->processed_at);
        Http::assertSentCount(1);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
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

    public function test_unknown_status_is_recorded_safely_without_transition_or_side_effects_before_acknowledgement(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $order = app(OrderCheckoutService::class)->checkout(
            $this->cartWithProduct(price: 10000, stock: 1),
            CheckoutPaymentIntents::klikQris(),
        );
        $payment = $order->payments()->sole();
        $payment->update([
            'provider_total_payment' => 10016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => now()->addMinutes(10),
            'expired_at' => now()->addMinutes(10),
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment),
        ]);
        $product = Product::firstOrFail();
        $paymentBefore = $payment->fresh()->getAttributes();
        $orderBefore = $order->fresh()->getAttributes();
        $productBefore = $product->fresh()->getAttributes();
        $inventoryMovementCount = InventoryMovement::count();
        $salesProofCount = SalesProofPost::count();
        Http::fake();

        $response = $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment->fresh(), [
            'status' => '  disputed.review-1  ',
            'signature' => 'UNKNOWN_SIGNATURE_SHOULD_NOT_PERSIST',
            'api_key' => 'UNKNOWN_API_KEY_SHOULD_NOT_PERSIST',
            'merchant_id' => 'UNKNOWN_MERCHANT_SHOULD_NOT_PERSIST',
            'qris_base64' => 'UNKNOWN_QR_SHOULD_NOT_PERSIST',
            'raw_payload' => ['secret' => 'UNKNOWN_RAW_SHOULD_NOT_PERSIST'],
            'internal_exception' => 'UNKNOWN_EXCEPTION_SHOULD_NOT_PERSIST',
        ]));

        $response->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($inventoryMovementCount, InventoryMovement::count());
        $this->assertSame($salesProofCount, SalesProofPost::count());
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertNothingSent();

        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame(hash('sha256', (string) $payment->merchant_ref), $event->merchant_ref);
        $this->assertSame('DISPUTED.REVIEW-1', $event->provider_status);
        $this->assertSame([
            'condition' => 'unknown_callback_status',
            'merchant_ref_hash' => hash('sha256', (string) $payment->merchant_ref),
            'status' => 'DISPUTED.REVIEW-1',
        ], $event->raw_payload);
        $this->assertNull($event->signature);
        $this->assertNotNull($event->processed_at);
        $this->assertStringNotContainsString((string) $payment->merchant_ref, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_duplicate_unknown_status_for_unknown_order_coalesces_into_one_safe_processed_event(): void
    {
        Http::fake();
        $merchantRef = 'UNKNOWN FULL MERCHANT REF 1234567890';
        $payload = [
            'order_id' => $merchantRef,
            'amount' => 10000,
            'total_amount' => 10016,
            'status' => ' review_required ',
            'signature' => 'FIRST_UNKNOWN_SIGNATURE',
            'credential' => 'UNKNOWN_CREDENTIAL',
            'merchant_id' => 'UNKNOWN_MERCHANT_ID',
        ];

        $this->postJson('/api/webhook/klikqris/sandbox', $payload)
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->postJson('/api/webhook/klikqris/sandbox', array_replace($payload, [
            'signature' => 'SECOND_UNKNOWN_SIGNATURE',
        ]))->assertOk()->assertExactJson(['success' => true]);

        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNull($event->payment_id);
        $this->assertSame(hash('sha256', $merchantRef), $event->merchant_ref);
        $this->assertSame('REVIEW_REQUIRED', $event->provider_status);
        $this->assertSame([
            'condition' => 'unknown_callback_status',
            'merchant_ref_hash' => hash('sha256', $merchantRef),
            'status' => 'REVIEW_REQUIRED',
        ], $event->raw_payload);
        $this->assertSame(2, $event->received_count);
        $this->assertNotNull($event->processed_at);
        $this->assertStringNotContainsString($merchantRef, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_same_unknown_status_for_different_unknown_orders_records_separate_events(): void
    {
        Http::fake();
        $payload = [
            'order_id' => 'UNKNOWN-ORDER-ONE',
            'amount' => 10000,
            'total_amount' => 10016,
            'status' => 'REVIEW_REQUIRED',
            'signature' => 'UNKNOWN_SIGNATURE',
        ];

        $this->postJson('/api/webhook/klikqris/sandbox', $payload)->assertOk();
        $this->postJson('/api/webhook/klikqris/sandbox', array_replace($payload, [
            'order_id' => 'UNKNOWN-ORDER-TWO',
        ]))->assertOk();

        $this->assertSame(2, PaymentEvent::query()
            ->where('provider', 'klikqris')
            ->where('event_type', PaymentEventType::Callback->value)
            ->count());
        $this->assertEqualsCanonicalizing(
            [hash('sha256', 'UNKNOWN-ORDER-ONE'), hash('sha256', 'UNKNOWN-ORDER-TWO')],
            PaymentEvent::query()->pluck('merchant_ref')->all(),
        );
        Http::assertNothingSent();
    }

    public function test_unsafe_unknown_status_tokens_are_rejected_without_event_or_status_call(): void
    {
        Http::fake();
        $payment = $this->payableKlikQrisPayment();

        foreach (["REVIEW\nREQUIRED", str_repeat('A', 41), 'REVIEW REQUIRED'] as $status) {
            $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
                'status' => $status,
            ]))->assertUnprocessable();
        }

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_expired_callback_preserves_known_non_paid_behavior(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        Http::fake();
        $payment = $this->payableKlikQrisPayment();
        $paymentBefore = $payment->getAttributes();
        $orderBefore = $payment->order->getAttributes();

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
            'status' => ' expired ',
        ]))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame($paymentBefore, $payment->fresh()->getAttributes());
        $this->assertSame($orderBefore, $payment->order->fresh()->getAttributes());
        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame('EXPIRED', $event->provider_status);
        $this->assertNull($event->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertNothingSent();
    }

    public function test_unknown_status_persistence_failure_returns_non_success_for_provider_retry(): void
    {
        Http::fake();
        $payment = $this->payableKlikQrisPayment();
        PaymentEvent::creating(function (PaymentEvent $event): void {
            if ($event->provider === 'klikqris') {
                throw new \RuntimeException('forced payment event persistence failure');
            }
        });

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
            'status' => 'REVIEW_REQUIRED',
        ]))->assertServerError();

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
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

    public function test_oversized_json_is_rejected_before_event_logging_or_status_calls(): void
    {
        Http::fake();

        $payload = $this->callbackPayload($this->payableKlikQrisPayment(), [
            'padding' => str_repeat('x', 70_000),
        ]);

        $this->postJson('/api/webhook/klikqris/sandbox', $payload)->assertStatus(413);

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_deferred_callback_reaches_the_runtime_worker_without_leaving_database_cutover_blockers(): void
    {
        Schema::create('runtime_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config()->set('queue.default', 'runtime');
        config()->set('queue.connections.runtime', array_replace(config('queue.connections.database'), [
            'table' => 'runtime_jobs',
            'after_commit' => false,
        ]));
        $payment = $this->payableKlikQrisPayment();
        $providerAvailable = false;
        Http::fake(function () use (&$providerAvailable, $payment) {
            return $providerAvailable
                ? Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200)
                : Http::response(['message' => 'temporary'], 500);
        });

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))
            ->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('runtime_jobs', 1);

        $providerAvailable = true;
        $this->artisan('queue:work', [
            'connection' => 'runtime',
            '--queue' => 'payments',
            '--once' => true,
        ])->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull(PaymentEvent::query()
            ->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
        $this->assertSame(0, DB::table('runtime_jobs')->where('queue', 'payments')->count());
    }

    public function test_status_transient_outage_is_acknowledged_and_readiness_faults_still_surface(): void
    {
        Queue::fake();
        $payment = $this->payableKlikQrisPayment();

        Http::fake(fn () => Http::response(['message' => 'temporary'], 500));
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Queue::assertPushed(VerifyKlikQrisWebhookPayment::class, fn (VerifyKlikQrisWebhookPayment $job): bool => $job->queue === 'payments');

        config()->set('services.klikqris.sandbox_api_key', null);
        Http::fake();
        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment));
    }

    public function test_invalid_status_json_fault_is_acknowledged_after_durable_callback_log(): void
    {
        Queue::fake();
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response('not-json', 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Queue::assertPushed(VerifyKlikQrisWebhookPayment::class);
    }

    public function test_status_outage_after_stored_callback_returns_success_and_persists_reconciliation_needed_event_without_secrets(): void
    {
        Queue::fake();
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
        Queue::assertPushed(VerifyKlikQrisWebhookPayment::class, fn (VerifyKlikQrisWebhookPayment $job): bool => $job->queue === 'payments');
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

    public function test_duplicate_callbacks_with_different_signatures_and_money_representations_coalesce(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
            'amount' => (string) $payment->amount,
            'total_amount' => (string) $payment->provider_total_payment,
            'signature' => 'first-signature',
        ]))->assertOk();
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment, [
            'amount' => $payment->amount,
            'total_amount' => $payment->provider_total_payment,
            'signature' => 'second-signature',
        ]))->assertOk();

        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertSame($payment->amount, $event->raw_payload['amount']);
        $this->assertSame($payment->provider_total_payment, $event->raw_payload['total_amount']);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_authoritative_success_after_local_expiry_uses_late_payment_semantics_without_resurrecting_order(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(price: 10000, stock: 1), CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();
        $payment->update([
            'provider_total_payment' => 10016,
            'provider_fee' => 16,
            'provider_payment_method' => 'qris',
            'provider_expires_at' => now()->addMinutes(10),
            'expired_at' => now()->addMinutes(10),
            'provider_payload' => KlikQrisSandboxScenarios::createPayload($payment),
        ]);
        $order->update(['expires_at' => now()->addMinutes(10)]);
        $product = Product::firstOrFail();

        $this->travelTo(now()->addMinutes(11));
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(OrderStatus::Expired, $payment->order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(0, $product->fresh()->reserved_stock);

        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));
        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($payment))->assertOk()->assertExactJson(['success' => true]);

        $payment->refresh();
        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(0, InventoryMovement::where('type', 'sale')->count());
        $this->assertSame(0, SalesProofPost::count());
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_callback_with_same_merchant_ref_as_pakasir_payment_finds_only_klikqris_row(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        $merchantRef = 'SHARED-MERCHANT-REF';
        $pakasir = Payment::factory()->create([
            'provider' => 'pakasir',
            'merchant_ref' => $merchantRef,
            'amount' => 10000,
            'status' => PaymentStatus::Pending,
        ]);
        $klikqris = $this->payableKlikQrisPayment();
        $klikqris->update(['merchant_ref' => $merchantRef]);
        $klikqris->order->update(['invoice_number' => 'INV-KLIK-SHARED']);
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($klikqris->fresh()), 200));

        $this->postJson('/api/webhook/klikqris/sandbox', $this->callbackPayload($klikqris->fresh()))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $pakasir->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $klikqris->fresh()->status);
        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame($klikqris->id, $event->payment_id);
    }

    public function test_duplicate_top_level_critical_json_keys_are_rejected_before_status_or_event(): void
    {
        Http::fake();
        $payment = $this->payableKlikQrisPayment();
        $json = json_encode($this->callbackPayload($payment), JSON_THROW_ON_ERROR);
        $json = str_replace('"amount":10000', '"amount":10000,"amount":"10000"', $json);

        $this->call('POST', '/api/webhook/klikqris/sandbox', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json)
            ->assertStatus(400);

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_production_environment_rejects_sandbox_webhook_before_persisting_or_calling_provider(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.env', 'production');
        config()->set('services.klikqris.sandbox_enabled', true);
        Http::fake();

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => 'unknown-production-order',
            'amount' => 10000,
            'total_amount' => 10016,
            'status' => 'PENDING',
        ])->assertNotFound();

        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_production_webhook_rejects_invalid_signature_before_event_or_status_request(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = $this->payableKlikQrisPayment();
        Http::fake();

        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->callbackPayload($payment, [
            'signature' => 'invalid-production-signature',
        ]), JSON_THROW_ON_ERROR))->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_production_webhook_rejects_missing_signature_before_event_or_status_request(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = $this->payableKlikQrisPayment();
        Http::fake();
        $payload = $this->callbackPayload($payment);
        unset($payload['signature']);

        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertUnauthorized();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('provider', 'klikqris')->count());
        Http::assertNothingSent();
    }

    public function test_ready_production_webhook_verifies_signature_and_fulfills_klikqris_payment(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $payload = $this->callbackPayload($payment);

        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Http::assertSentCount(1);
    }

    public function test_production_callback_mismatch_stays_unpaid_after_authoritative_status_check(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment, [
            'total_amount' => $payment->provider_total_payment + 1,
        ]), 200));

        $payload = $this->callbackPayload($payment);

        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertOk()->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::query()->where('provider', 'klikqris')->sole()->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Http::assertSentCount(1);
    }

    public function test_duplicate_production_callbacks_are_idempotent_after_authoritative_success(): void
    {
        Queue::fake([SendTelegramReceipt::class]);
        KlikQrisSandboxScenarios::configureReadyProduction();
        $payment = $this->payableKlikQrisPayment();
        Http::fake(fn () => Http::response(KlikQrisSandboxScenarios::statusPayload($payment), 200));

        $firstBody = json_encode($this->callbackPayload($payment), JSON_THROW_ON_ERROR);
        $secondBody = json_encode($this->callbackPayload($payment), JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $firstBody)->assertOk();
        $this->call('POST', '/api/webhook/klikqris', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $secondBody)->assertOk();

        $event = PaymentEvent::query()->where('provider', 'klikqris')->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
        Http::assertSentCount(1);
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
            'klikqris_transaction_signature' => 'opaque-callback-signature',
        ]);
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

    private function cartWithProduct(int $price = 1000, int $stock = 10, int $quantity = 1): Cart
    {
        $customer = Customer::factory()->create(['telegram_id' => fake()->unique()->numberBetween(100000, 999999999)]);
        $product = Product::factory()->create(['stock' => $stock, 'reserved_stock' => 0, 'price_amount' => $price]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $cart;
    }
}
