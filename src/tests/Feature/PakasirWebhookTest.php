<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Jobs\DeleteAdminPendingOrderNotification;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendTelegramReceipt;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Telegram\SalesProofPostFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PakasirWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pakasir', [
            'slug' => 'warung-ai',
            'api_key' => 'pakasir-secret',
            'base_url' => 'https://app.pakasir.com',
            'redirect_url' => null,
            'qris_only' => true,
        ]);
    }

    public function test_identical_completed_callback_replays_coalesce_to_one_processed_event_and_once_only_fulfillment(): void
    {
        Queue::fake();
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);
        $firstDeliveryAt = Carbon::parse('2026-07-05 10:00:00', config('app.timezone'));
        Carbon::setTestNow($firstDeliveryAt);

        try {
            $payload = $this->payload($payment->merchant_ref, $payment->amount);

            $this->postJson('/api/webhook/pakasir', $payload)->assertOk();

            $firstEvent = PaymentEvent::query()
                ->where('event_type', PaymentEventType::Callback)
                ->firstOrFail();
            $firstReceivedAt = $firstEvent->received_at->copy();
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame('pakasir', $firstEvent->provider);
            $this->assertSame($payment->id, $firstEvent->payment_id);
            $this->assertSame($payment->merchant_ref, $firstEvent->merchant_ref);
            $this->assertSame('completed', $firstEvent->provider_status);
            $this->assertSame($payload, $firstEvent->raw_payload);
            $this->assertNotNull($firstEvent->processed_at);
            $this->assertSame(1, $firstEvent->received_count);

            foreach ([5, 10, 15] as $secondsAfterFirstDelivery) {
                Carbon::setTestNow($firstDeliveryAt->copy()->addSeconds($secondsAfterFirstDelivery));

                $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
            }

            $event = PaymentEvent::query()
                ->where('event_type', PaymentEventType::Callback)
                ->firstOrFail();
            $this->assertSame($firstEvent->id, $event->id);
            $this->assertTrue($event->received_at->equalTo($firstReceivedAt));
            $this->assertSame(4, $event->received_count);
            $this->assertNotNull($event->last_received_at);
            $this->assertTrue(Carbon::parse($event->last_received_at)->greaterThan($firstReceivedAt));
            $this->assertNotNull($event->processed_at);
            $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::Callback)->count());
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->orderId === $order->id);
            Queue::assertPushed(SendTelegramReceipt::class, 1);
            Queue::assertPushedOn('notifications', DeleteAdminPendingOrderNotification::class, fn (DeleteAdminPendingOrderNotification $job): bool => $job->orderId === $order->id);
            Queue::assertPushedOn('notifications', SendAdminOrderPaidNotification::class, fn (SendAdminOrderPaidNotification $job): bool => $job->orderId === $order->id);
            Queue::assertPushed(PublishSalesProofPost::class, 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_verified_completed_callback_after_local_expiry_marks_late_paid_without_fulfillment(): void
    {
        Queue::fake();
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir', now()->subMinute());
        $payment = $order->payments()->firstOrFail();

        app(OrderStateMachine::class)->expirePendingOrder($order);

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());

        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();

        $payment->refresh();
        $order->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(2, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, SalesProofPost::count());
        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::Callback, $event->event_type);
        $this->assertNotNull($event->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Queue::assertPushedOn('notifications', DeleteAdminPendingOrderNotification::class, fn (DeleteAdminPendingOrderNotification $job): bool => $job->orderId === $order->id);
        Queue::assertNotPushed(SendAdminOrderPaidNotification::class);
        Queue::assertNotPushed(PublishSalesProofPost::class);
    }

    public function test_sales_proof_post_is_sent_once_and_privacy_safe_for_pakasir_paid_order(): void
    {
        config()->set('queue.default', 'sync');
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response(['transaction' => ['status' => 'completed', 'amount' => 50000, 'order_id' => '*']]),
            'https://api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);
        config()->set('services.telegram.token', 'bot-token');
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $order->customer()->update(['username' => 'AdamBuyer']);
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();
        app()->call([new PublishSalesProofPost($order->id), 'handle']);
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100123'
            && str_contains((string) ($request['text'] ?? ''), 'Order sukses'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && (string) ($request['chat_id'] ?? '') !== '-100123'
            && str_contains((string) ($request['text'] ?? ''), 'Produk terjual'));
        $this->assertSame(1, SalesProofPost::where('status', SalesProofPost::STATUS_SENT)->count());
        $this->assertSame('77', SalesProofPost::firstOrFail()->telegram_message_id);
        $copy = app(SalesProofPostFormatter::class)->format($order->fresh(['customer', 'orderItems']));
        $this->assertStringContainsString('Ad***', $copy);
        $this->assertStringNotContainsString($order->invoice_number, $copy);
        $this->assertStringNotContainsString((string) $order->total_amount, $copy);
    }

    public function test_wrong_amount_wrong_project_unknown_order_api_mismatch_and_api_failure_fail_closed(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 6), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount + 1))->assertUnprocessable();
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount, ['project' => 'wrong']))->assertUnprocessable();
        $this->postJson('/api/webhook/pakasir', $this->payload('UNKNOWN', $payment->amount))->assertUnprocessable();

        $this->fakeDetail($payment->merchant_ref, $payment->amount, ['status' => 'pending']);
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $apiFailureOrder = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $apiFailurePayment = $apiFailureOrder->payments()->firstOrFail();
        Http::fake(['https://app.pakasir.com/api/transactiondetail*' => Http::response(['message' => 'down'], 500)]);
        $this->postJson('/api/webhook/pakasir', $this->payload($apiFailurePayment->merchant_ref, $apiFailurePayment->amount))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(5, PaymentEvent::count());
        $this->assertSame(0, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertNull(PaymentEvent::where('merchant_ref', 'UNKNOWN')->firstOrFail()->payment_id);
        Queue::assertNothingPushed();
    }

    public function test_digit_string_amounts_are_accepted_for_callback_and_transaction_detail_verification(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $amount = (string) $payment->amount;
        $this->fakeProviderDetail($payment->merchant_ref, $amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $amount))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertNotNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_malformed_callback_amounts_fail_closed_before_paid_transition(): void
    {
        Queue::fake();

        foreach ($this->malformedAmountCases(10000) as $case => $amount) {
            $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
            $payment = $order->payments()->firstOrFail();
            $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

            $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $amount))->assertUnprocessable();

            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status, "{$case} marked payment paid");
            $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status, "{$case} advanced order status");
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count(), "{$case} created sale inventory movement");
            $this->assertSame(0, PaymentEvent::where('merchant_ref', $payment->merchant_ref)->whereNotNull('processed_at')->count(), "{$case} processed a payment event");
        }

        Queue::assertNothingPushed();
    }

    public function test_malformed_transaction_detail_amounts_fail_closed_during_callback_verification(): void
    {
        Queue::fake();

        foreach ($this->malformedAmountCases(10000) as $case => $amount) {
            $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
            $payment = $order->payments()->firstOrFail();
            $this->fakeProviderDetail($payment->merchant_ref, $amount);

            $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status, "{$case} marked payment paid");
            $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status, "{$case} advanced order status");
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count(), "{$case} created sale inventory movement");
            $event = PaymentEvent::where('merchant_ref', $payment->merchant_ref)->firstOrFail();
            $this->assertNull($event->processed_at, "{$case} processed callback event");
        }

        Queue::assertNothingPushed();
    }

    public function test_native_qris_callback_fails_closed_when_transaction_detail_method_mismatches(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $payment->update(['provider_payment_method' => 'qris']);
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount, ['payment_method' => 'hosted']);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount, ['payment_method' => 'qris']))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
        Queue::assertNothingPushed();
    }

    public function test_callback_rejects_nested_transaction_when_top_level_envelope_is_mismatched(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeProviderDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', [
            'amount' => $payment->amount,
            'order_id' => 'UNKNOWN',
            'project' => 'wrong-project',
            'status' => 'pending',
            'transaction' => [
                'amount' => $payment->amount,
                'order_id' => $payment->merchant_ref,
                'project' => 'warung-ai',
                'status' => 'completed',
            ],
        ])->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $event = PaymentEvent::firstOrFail();
        $this->assertNull($event->payment_id);
        $this->assertSame('UNKNOWN', $event->merchant_ref);
        $this->assertSame('pending', $event->provider_status);
        $this->assertNull($event->processed_at);
        Queue::assertNothingPushed();
    }

    public function test_completed_callback_with_missing_api_key_fails_closed_after_logging(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.api_key', '');

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_completed_callback_with_missing_project_slug_fails_closed_after_logging(): void
    {
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.slug', '');

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertUnprocessable();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
        Http::assertNothingSent();
    }

    public function test_pakasir_webhook_is_throttled_before_processor_work(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 1);
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertOk();
        $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $payment->amount))->assertTooManyRequests();

        $this->assertSame(1, PaymentEvent::count());
        Http::assertSentCount(1);
    }

    public function test_unknown_pakasir_callbacks_with_rotating_order_ids_share_webhook_rate_limit(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 1);
        Queue::fake();
        Http::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.111']);

        $this->postJson('/api/webhook/pakasir', $this->payload('issue-111-unknown-a', 10000))->assertUnprocessable();
        $this->assertLessThanOrEqual(1, PaymentEvent::count());

        $this->postJson('/api/webhook/pakasir', $this->payload('issue-111-unknown-b', 10000))->assertTooManyRequests();

        $this->assertLessThanOrEqual(1, PaymentEvent::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_identical_callback_replay_after_pending_provider_detail_reuses_unprocessed_event_and_processes_it(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 60);
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $firstDeliveryAt = Carbon::parse('2026-07-05 10:30:00', config('app.timezone'));
        Carbon::setTestNow($firstDeliveryAt);
        $detailStatuses = ['pending', 'completed'];
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => function () use ($payment, &$detailStatuses) {
                return Http::response([
                    'transaction' => [
                        'project' => 'warung-ai',
                        'amount' => $payment->amount,
                        'order_id' => $payment->merchant_ref,
                        'status' => array_shift($detailStatuses) ?? 'completed',
                    ],
                ], 200);
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        try {
            $payload = $this->payload($payment->merchant_ref, $payment->amount);

            $this->postJson('/api/webhook/pakasir', $payload)->assertUnprocessable();

            $unprocessedEvent = PaymentEvent::query()
                ->where('event_type', PaymentEventType::Callback)
                ->firstOrFail();
            $firstReceivedAt = $unprocessedEvent->received_at->copy();
            $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
            $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
            $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::Callback)->count());
            $this->assertNull($unprocessedEvent->processed_at);
            $this->assertSame(1, $unprocessedEvent->received_count);
            Queue::assertNothingPushed();

            Carbon::setTestNow($firstDeliveryAt->copy()->addSeconds(5));

            $this->postJson('/api/webhook/pakasir', $payload)->assertOk();

            $event = PaymentEvent::query()
                ->where('event_type', PaymentEventType::Callback)
                ->firstOrFail();
            $this->assertCount(2, Http::recorded(fn ($request) => str_contains($request->url(), '/api/transactiondetail')));
            $this->assertSame($unprocessedEvent->id, $event->id);
            $this->assertTrue($event->received_at->equalTo($firstReceivedAt));
            $this->assertSame(2, $event->received_count);
            $this->assertNotNull($event->last_received_at);
            $this->assertTrue(Carbon::parse($event->last_received_at)->greaterThan($firstReceivedAt));
            $this->assertNotNull($event->processed_at);
            $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
            $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
            $this->assertSame(1, PaymentEvent::query()->where('event_type', PaymentEventType::Callback)->count());
            $this->assertSame(1, PaymentEvent::query()->whereNotNull('processed_at')->count());
            Queue::assertPushedOn('notifications', SendTelegramReceipt::class, fn (SendTelegramReceipt $job): bool => $job->orderId === $order->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_noisy_pakasir_payload_is_rejected_before_audit_logging_or_provider_lookup(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 60);
        Http::fake();

        $this->postJson('/api/webhook/pakasir', ['noise' => 'not-a-provider-callback'])->assertUnprocessable();

        $this->assertSame(0, PaymentEvent::count());
        Http::assertNothingSent();
    }

    public function test_malformed_callback_amounts_are_rejected_before_audit_logging_or_provider_lookup(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 60);
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        foreach ($this->malformedAmountCases(10000) as $case => $amount) {
            $this->postJson('/api/webhook/pakasir', $this->payload($payment->merchant_ref, $amount))->assertUnprocessable();

            $this->assertSame(0, PaymentEvent::count(), "{$case} created a payment event");
            Http::assertNothingSent();
        }
    }

    public function test_non_scalar_callback_fields_are_rejected_before_audit_logging_or_provider_lookup(): void
    {
        config()->set('services.pakasir.webhook_rate_limit_per_minute', 60);
        Http::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        $validPayload = $this->payload($payment->merchant_ref, $payment->amount);

        foreach ([
            'array order_id' => ['order_id' => ['ORDER-123']],
            'object order_id' => ['order_id' => (object) ['value' => 'ORDER-123']],
            'array project' => ['project' => ['warung-ai']],
            'object project' => ['project' => (object) ['value' => 'warung-ai']],
            'array status' => ['status' => ['completed']],
            'object status' => ['status' => (object) ['value' => 'completed']],
            'array payment_method' => ['payment_method' => ['qris']],
            'object payment_method' => ['payment_method' => (object) ['value' => 'qris']],
        ] as $case => $override) {
            $this->postJson('/api/webhook/pakasir', array_merge($validPayload, $override))->assertUnprocessable();

            $this->assertSame(0, PaymentEvent::count(), "{$case} created a payment event");
            Http::assertNothingSent();
        }
    }

    public function test_reconcile_command_processes_completed_pakasir_payment_and_logs_reconciliation(): void
    {
        Queue::fake();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->artisan('pakasir:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::Reconciliation, $event->event_type);
        $this->assertSame($payment->merchant_ref, $event->merchant_ref);
        $this->assertSame('completed', $event->provider_status);
        $this->assertSame('completed', $event->raw_payload['transaction']['status']);
        $this->assertNotNull($event->processed_at);
    }

    public function test_reconcile_command_marks_cancelled_payment_late_paid_without_fulfillment(): void
    {
        Queue::fake();
        StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'sales_proof_enabled' => true, 'telegram_chat_id' => '-100123']);
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();

        app(OrderStateMachine::class)->cancelPendingOrder($order);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());

        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->artisan('pakasir:reconcile')
            ->expectsOutputToContain('pakasir:reconcile completed cleanly: processed=1 skipped=0 failed=0')
            ->assertSuccessful();

        $payment->refresh();
        $order->refresh();
        $product = Product::firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->order_status);
        $this->assertSame(2, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Reservation)->count());
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, SalesProofPost::count());
        $event = PaymentEvent::firstOrFail();
        $this->assertSame(PaymentEventType::Reconciliation, $event->event_type);
        $this->assertNotNull($event->processed_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
        Queue::assertNotPushed(PublishSalesProofPost::class);
    }

    public function test_reconcile_command_does_not_poll_old_released_payments_indefinitely(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        app(OrderStateMachine::class)->cancelPendingOrder($order);
        $payment->forceFill(['updated_at' => now()->subHours(73)])->save();
        $this->fakeDetail($payment->merchant_ref, $payment->amount);

        $this->artisan('pakasir:reconcile')
            ->expectsOutputToContain('pakasir:reconcile completed cleanly: processed=0 skipped=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
        Http::assertNothingSent();
    }

    public function test_pakasir_reconciliation_is_scheduled_every_minute_ahead_of_pending_order_expiry(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $reconcilePattern = '/^\s*\*\s+\*\s+\*\s+\*\s+\*\s+php artisan pakasir:reconcile --chunk=100\b/m';
        $expirePendingPattern = '/^\s*\*\s+\*\s+\*\s+\*\s+\*\s+php artisan orders:expire-pending\b/m';

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match($reconcilePattern, $output, $reconcileMatches, PREG_OFFSET_CAPTURE));
        $this->assertSame(1, preg_match($expirePendingPattern, $output, $expirePendingMatches, PREG_OFFSET_CAPTURE));
        $this->assertLessThan($expirePendingMatches[0][1], $reconcileMatches[0][1]);
    }

    public function test_reconcile_command_skips_mismatches_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount, ['amount' => $payment->amount + 1]);

        $this->artisan('pakasir:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_reconcile_command_skips_malformed_transaction_detail_amount_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $this->fakeDetail($payment->merchant_ref, $payment->amount, ['amount' => $payment->amount.'.00']);

        $this->artisan('pakasir:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertNull(PaymentEvent::firstOrFail()->processed_at);
    }

    public function test_reconcile_pending_native_qris_tightens_expiry_then_local_expiry_releases_stock(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        $providerExpiry = now()->subMinute()->startOfSecond();
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir', now()->addHour());
        $payment = $order->payments()->firstOrFail();
        $payment->update(['provider_payment_method' => 'qris']);
        $this->fakeDetail($payment->merchant_ref, $payment->amount, [
            'status' => 'pending',
            'payment_method' => 'qris',
            'expired_at' => $providerExpiry->utc()->toAtomString(),
        ]);

        $this->artisan('pakasir:reconcile')->assertSuccessful();
        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertTrue($payment->fresh()->expired_at?->equalTo($providerExpiry));
        $this->assertTrue($order->fresh()->expires_at?->equalTo($providerExpiry));
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovementType::Release)->count());
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/api/transactiondetail')));
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), '/api/transactioncancel')));
    }

    public function test_reconcile_command_reports_api_failures_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        Http::fake(['https://app.pakasir.com/api/transactiondetail*' => Http::response(['message' => 'down'], 500)]);

        $this->artisan('pakasir:reconcile')
            ->expectsOutputToContain('pakasir:reconcile completed with partial failures: processed=0 skipped=0 failed=1')
            ->assertFailed();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_reconcile_command_redacts_transaction_detail_url_secrets_from_failures_without_mutating_order(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(stock: 2), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        $secret = 'SECRET-issue-108';
        $providerUrl = 'https://app.pakasir.com/api/transactiondetail?project=warung-ai&amount='
            .$payment->amount.'&order_id='.$payment->merchant_ref.'&api_key='.$secret;

        $this->app->bind(PakasirProviderAdapter::class, fn () => new class($providerUrl) implements PakasirProviderAdapter
        {
            public function __construct(private readonly string $providerUrl) {}

            public function hostedPaymentUrl(Payment $payment): string
            {
                return 'https://fake-pakasir.test/pay/'.$payment->merchant_ref;
            }

            public function createQrisTransaction(Payment $payment): PakasirQrisTransaction
            {
                throw new \BadMethodCallException('QRIS create is not used by this test.');
            }

            public function cancelTransaction(Payment $payment): array
            {
                throw new \BadMethodCallException('Transaction cancel is not used by this test.');
            }

            public function transactionDetail(Payment $payment): array
            {
                throw new \RuntimeException('Pakasir Transaction Detail transport failure: GET '.$this->providerUrl);
            }
        });

        $exitCode = Artisan::call('pakasir:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Pakasir reconciliation failed payment '.$payment->id, $output);
        $this->assertStringContainsString('pakasir:reconcile completed with partial failures: processed=0 skipped=0 failed=1', $output);
        $this->assertStringNotContainsString($secret, $output);
        $this->assertStringNotContainsString('api_key', $output);
        $this->assertStringNotContainsString($providerUrl, $output);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, PaymentEvent::count());
    }

    public function test_reconcile_command_fails_safely_when_api_key_is_missing(): void
    {
        $order = app(OrderCheckoutService::class)->checkout($this->cartWithProduct(), 'qris', 'pakasir');
        $payment = $order->payments()->firstOrFail();
        config()->set('services.pakasir.api_key', '');
        Http::fake();

        $this->artisan('pakasir:reconcile')->assertFailed();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
        Http::assertNothingSent();
    }

    public function test_expire_pending_orders_sends_telegram_expired_notice(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'first_name' => 'Buyer']);
        $product = Product::factory()->create(['name' => 'Ready API KEY', 'stock' => 2, 'reserved_stock' => 0, 'price_amount' => 7000]);
        $cart = Cart::factory()->create(['customer_id' => $customer->id, 'status' => CartStatus::Active, 'expires_at' => now()->addHour()]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $order = app(OrderCheckoutService::class)->checkout($cart, 'hosted', 'pakasir', now()->subMinute());

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->payments()->firstOrFail()->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '12345'
            && str_contains($request['text'], '⏳ Order kedaluwarsa')
            && str_contains($request['text'], $order->invoice_number)
            && str_contains($request['text'], 'Ready API KEY')
            && str_contains($request['text'], 'Rp7.000'));
    }

    private function payload(string $orderId, mixed $amount, array $overrides = []): array
    {
        return array_merge([
            'amount' => $amount,
            'order_id' => $orderId,
            'project' => 'warung-ai',
            'status' => 'completed',
            'payment_method' => 'qris',
            'completed_at' => now()->toISOString(),
        ], $overrides);
    }

    private function fakeDetail(string $orderId, mixed $amount, array $overrides = []): void
    {
        Http::fake([
            'https://app.pakasir.com/api/transactiondetail*' => Http::response([
                'transaction' => array_merge([
                    'project' => 'warung-ai',
                    'amount' => $amount,
                    'order_id' => $orderId,
                    'status' => 'completed',
                ], $overrides),
            ], 200),
            'https://app.pakasir.com/api/transactioncancel' => Http::response(['transaction' => ['status' => 'cancelled']], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    private function fakeProviderDetail(string $orderId, mixed $amount, array $overrides = []): void
    {
        $this->app->bind(PakasirProviderAdapter::class, fn () => new class($orderId, $amount, $overrides) implements PakasirProviderAdapter
        {
            public function __construct(
                private readonly string $orderId,
                private readonly mixed $amount,
                private readonly array $overrides,
            ) {}

            public function hostedPaymentUrl(Payment $payment): string
            {
                return 'https://fake-pakasir.test/pay/'.$payment->merchant_ref;
            }

            public function createQrisTransaction(Payment $payment): PakasirQrisTransaction
            {
                throw new \BadMethodCallException('QRIS create is not used by webhook tests.');
            }

            public function cancelTransaction(Payment $payment): array
            {
                throw new \BadMethodCallException('Transaction cancel is not used by webhook tests.');
            }

            public function transactionDetail(Payment $payment): array
            {
                return [
                    'transaction' => array_merge([
                        'project' => 'warung-ai',
                        'amount' => $this->amount,
                        'order_id' => $this->orderId,
                        'status' => 'completed',
                    ], $this->overrides),
                ];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function malformedAmountCases(int $expectedAmount): array
    {
        return [
            'decimal string' => $expectedAmount.'.00',
            'mixed string' => $expectedAmount.' paid',
            'array' => [$expectedAmount],
            'empty string' => '',
            'null' => null,
            'zero integer' => 0,
            'zero digit string' => '0',
            'negative integer' => -$expectedAmount,
            'negative string' => '-'.$expectedAmount,
        ];
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
