<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\VerifySakurupiahWebhookPayment;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\OrderLifecycle;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCallback;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahHistoryItem;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahStatusUnavailableException;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SakurupiahWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        config()->set('queue.default', 'sync');
    }

    public function test_invalid_signature_json_event_and_status_are_rejected_without_event_or_lifecycle_mutation(): void
    {
        $payment = $this->payment();
        $before = $payment->fresh()->getAttributes();

        $this->postCallback($this->callbackBody($payment), signature: 'invalid-signature')
            ->assertUnauthorized();
        $this->postCallback('{bad json')
            ->assertStatus(400);
        $this->postCallback($this->callbackBody($payment), event: 'invoice.created')
            ->assertUnprocessable();
        $this->postCallback($this->callbackBody($payment, ['status' => 'failed', 'status_kode' => 0]))
            ->assertUnprocessable();

        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_duplicate_critical_json_keys_are_rejected_before_persistence(): void
    {
        $payment = $this->payment();
        $body = '{"trx_id":"'.$payment->provider_reference.'","trx_id":"'.$payment->provider_reference.'","merchant_ref":"'.$payment->merchant_ref.'","status":"pending","status_kode":0}';

        $this->postCallback($body)->assertStatus(400);

        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_oversized_callback_body_is_rejected_before_event_persistence(): void
    {
        $payment = $this->payment();
        $body = $this->callbackBody($payment, ['message' => str_repeat('x', 70_000)]);

        $this->postCallback($body)->assertStatus(413);

        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_pending_callback_is_logged_once_and_never_calls_provider(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $body = $this->callbackBody($payment, ['status' => 'pending', 'status_kode' => 0]);
        $this->postCallback($body)->assertOk()->assertExactJson(['success' => true]);
        $this->postCallback($body)->assertOk()->assertExactJson(['success' => true]);

        $event = PaymentEvent::query()
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->where('event_type', PaymentEventType::Callback->value)
            ->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertNotNull($event->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertArrayNotHasKey('signature', $event->raw_payload);
        Queue::assertNothingPushed();
    }

    public function test_callback_acknowledges_without_provider_lookup_when_shared_payment_lock_is_held(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $lock = Cache::lock('sakurupiah:payment:'.$payment->getKey(), 90);
        $this->assertTrue($lock->get());
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        try {
            $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
                ->assertOk()
                ->assertExactJson(['success' => true]);
        } finally {
            $lock->release();
        }

        $this->assertNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_queued_verification_retries_when_shared_payment_lock_is_held(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $payment = $this->payment();
        $lock = Cache::lock('sakurupiah:payment:'.$payment->getKey(), 90);
        $this->assertTrue($lock->get());
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        try {
            $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
                ->assertOk();
            $event = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole();

            $this->expectException(SakurupiahStatusUnavailableException::class);
            app(SakurupiahCallbackProcessor::class)->processQueuedEvent($event);
        } finally {
            $lock->release();
        }
    }

    public function test_paid_callback_requires_authoritative_amount_evidence_and_defers_when_status_has_no_amount(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful),
        );
        $provider->shouldReceive('transactionHistory')->once()->andReturn([]);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_PROVIDER_BERHASIL,
            $payment->provider_create_state,
        );
        $this->assertSame(OrderStatus::PendingPayment, $payment->order->fresh()->order_status);
        $event = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNull($event->processed_at);
        Queue::assertPushed(VerifySakurupiahWebhookPayment::class, fn (VerifySakurupiahWebhookPayment $job): bool => $job->eventId === $event->id);
    }

    public function test_queued_verification_retries_when_authoritative_status_remains_pending(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->twice()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Pending),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk();
        $event = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole();

        $this->expectException(SakurupiahStatusUnavailableException::class);
        (new VerifySakurupiahWebhookPayment($event->id))->handle(app(SakurupiahCallbackProcessor::class));
    }

    public function test_authoritative_currency_mismatch_never_marks_paid(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, $payment->amount, 'USD'),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_INVALID_PROVIDER_RESULT,
            $payment->provider_create_state,
        );
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_paid_callback_accepts_a_matching_history_amount_when_status_omits_amount(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful),
        );
        $provider->shouldReceive('transactionHistory')->once()->andReturn([
            new SakurupiahHistoryItem(
                trxId: (string) $payment->provider_reference,
                merchantRef: (string) $payment->merchant_ref,
                paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
                amount: $payment->amount,
                status: SakurupiahTransactionStatus::Successful,
                sanitizedPayload: [
                    'data' => [[
                        'trx_id' => $payment->provider_reference,
                        'merchant_ref' => $payment->merchant_ref,
                        'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
                        'amount' => (string) $payment->amount,
                        'status' => 'berhasil',
                    ]],
                ],
            ),
        ]);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_paid_callback_accepts_the_recorded_final_total_when_the_buyer_bears_the_fee(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful),
        );
        // Live provider behaviour for `merchant_fee = 2`: the history row
        // reports the final total the customer paid, not the base amount.
        $provider->shouldReceive('transactionHistory')->once()->andReturn([
            new SakurupiahHistoryItem(
                trxId: (string) $payment->provider_reference,
                merchantRef: (string) $payment->merchant_ref,
                paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
                amount: (int) $payment->provider_total_payment,
                status: SakurupiahTransactionStatus::Successful,
                sanitizedPayload: [
                    'data' => [[
                        'trx_id' => $payment->provider_reference,
                        'merchant_ref' => $payment->merchant_ref,
                        'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
                        'amount' => (string) $payment->provider_total_payment,
                        'status' => 'berhasil',
                    ]],
                ],
            ),
        ]);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $payment->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Processing, $payment->order->fresh()->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
    }

    public function test_paid_callback_rejects_a_history_amount_the_payment_does_not_own(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful),
        );
        $provider->shouldReceive('transactionHistory')->once()->andReturn([
            new SakurupiahHistoryItem(
                trxId: (string) $payment->provider_reference,
                merchantRef: (string) $payment->merchant_ref,
                paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
                amount: (int) $payment->provider_total_payment + 1,
                status: SakurupiahTransactionStatus::Successful,
                sanitizedPayload: [
                    'data' => [[
                        'trx_id' => $payment->provider_reference,
                        'merchant_ref' => $payment->merchant_ref,
                        'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
                        'amount' => (string) ((int) $payment->provider_total_payment + 1),
                        'status' => 'berhasil',
                    ]],
                ],
            ),
        ]);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
    }

    public function test_paid_callback_requires_matching_authoritative_amount_before_order_lifecycle(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);
        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, SalesProofPost::query()->count());
        $event = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertNotNull($event->processed_at);
        Queue::assertNotPushed(VerifySakurupiahWebhookPayment::class);
    }

    public function test_paid_callback_duplicate_materializes_each_fulfillment_effect_once(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 930002],
            ]),
        ]);
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'admin_telegram_chat_id' => '123456',
            'is_active' => true,
        ]);
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $payment = $this->payment();
        $payment->order->customer()->update(['telegram_id' => 123456]);
        $orderItem = $payment->order->orderItems()->sole();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $payload = $this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]);
        $this->postCallback($payload)->assertOk();
        $this->postCallback($payload)->assertOk();
        $receiptJob = null;
        Queue::assertPushed(SendTelegramReceipt::class, function (SendTelegramReceipt $job) use (&$receiptJob, $payment): bool {
            $receiptJob = $job;

            return $job->orderId === (string) $payment->order_id;
        });
        $this->assertNotNull($receiptJob);
        $receiptJob->handle(
            app(TelegramOutboundEffectService::class),
            app(ReceiptDeliveryPipeline::class),
        );
        Queue::assertPushed(SendTelegramReceipt::class, 1);

        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, ProductStockItem::query()->where('order_item_id', $orderItem->id)->count());
        $this->assertSame(1, SalesProofPost::query()->where('order_id', $order->id)->count());

        foreach ([
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            TelegramOutboundEffectService::TYPE_ADMIN_PAID,
            TelegramOutboundEffectService::TYPE_SALES_PROOF,
        ] as $effectType) {
            $this->assertSame(
                1,
                TelegramOutboundEffect::query()
                    ->where('effect_type', $effectType)
                    ->where('source_id', (string) $order->id)
                    ->count(),
            );
        }
    }

    public function test_provider_outage_acknowledges_callback_and_queues_only_on_a_served_connection(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andThrow(new \RuntimeException('provider secret must not escape'));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $response = $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]));

        $response->assertOk()->assertExactJson(['success' => true]);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
        Queue::assertPushed(VerifySakurupiahWebhookPayment::class);
        $this->assertStringNotContainsString('provider secret', $response->getContent());
    }

    public function test_expired_callback_applies_only_after_authoritative_expired_status(): void
    {
        Queue::fake();
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Expired),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'expired', 'status_kode' => 2]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertSame(OrderStatus::Expired, $payment->order->fresh()->order_status);
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_late_authoritative_paid_callback_creates_manual_review_without_fulfilling_terminal_order(): void
    {
        Queue::fake();
        $payment = $this->payment();
        app(OrderLifecycle::class)->applyPaymentExpired($payment);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('reason', ManualFulfillmentReview::REASON_LATE_PAYMENT)->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_unknown_payment_is_acknowledged_without_provider_lookup_and_is_stored_unlinked(): void
    {
        Queue::fake();
        $body = json_encode([
            'trx_id' => 'trx-unknown-413',
            'merchant_ref' => 'INV-UNKNOWN-413',
            'status' => 'berhasil',
            'status_kode' => 1,
        ], JSON_THROW_ON_ERROR);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->never();
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($body)->assertOk()->assertExactJson(['success' => true]);

        $event = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole();
        $this->assertNull($event->payment_id);
        $this->assertNotNull($event->processed_at);
        Queue::assertNothingPushed();
    }

    public function test_paid_callback_on_overdue_but_still_pending_order_never_auto_fulfills(): void
    {
        Queue::fake();
        $payment = $this->payment();
        // The scheduler has not expired the order yet, but the local deadline
        // has already passed: the callback processor must release the pending
        // order before the late paid observation, so the paid evidence cannot
        // auto-fulfill or re-reserve stock.
        $order = $payment->order->fresh();
        $order->update([
            'expires_at' => now()->subMinute(),
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $payment->update([
            'expired_at' => now()->subMinute(),
            'provider_expires_at' => now()->subMinute(),
            'status' => PaymentStatus::Pending,
        ]);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $this->postCallback($this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]))
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $payment->refresh();
        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Expired, $order->order_status);
        $this->assertSame(1, ManualFulfillmentReview::query()->where('reason', ManualFulfillmentReview::REASON_LATE_PAYMENT)->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertNotNull(PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->sole()->processed_at);
    }

    public function test_out_of_order_callbacks_cause_one_paid_sale_and_stale_callbacks_are_acknowledged(): void
    {
        Queue::fake();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $paid = $this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]);
        $expired = $this->callbackBody($payment, ['status' => 'expired', 'status_kode' => 2]);
        $pending = $this->callbackBody($payment, ['status' => 'pending', 'status_kode' => 0]);

        // A paid callback arrives first and transitions exactly once.
        $this->postCallback($paid)->assertOk()->assertExactJson(['success' => true]);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);

        // A stale expired callback and a stale pending replay are acknowledged
        // without any further lifecycle mutation.
        $this->postCallback($expired)->assertOk()->assertExactJson(['success' => true]);
        $this->postCallback($pending)->assertOk()->assertExactJson(['success' => true]);

        $order = $payment->order->fresh();
        $orderItem = $order->orderItems()->sole();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(1, ProductStockItem::query()->where('order_item_id', $orderItem->id)->count());
        $this->assertSame(1, SalesProofPost::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, ManualFulfillmentReview::query()->count());
        $events = PaymentEvent::query()->where('event_type', PaymentEventType::Callback->value)->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame([1, 1, 1], $events->pluck('received_count')->all());
        $this->assertTrue($events->every(fn (PaymentEvent $event): bool => $event->processed_at !== null));
    }

    public function test_paid_callback_with_numeric_only_stock_records_one_review_without_releasing_credentials(): void
    {
        Queue::fake();
        config()->set('services.telegram.token', 'bot-token');
        ManualPaymentSetting::create([
            'payment_mode' => PaymentProvider::MANUAL,
            'admin_telegram_chat_id' => '123456',
            'is_active' => true,
        ]);
        $payment = $this->payment(managed: false);
        $order = $payment->order;
        $orderItem = $order->orderItems()->sole();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn(
            $this->providerStatus($payment, SakurupiahTransactionStatus::Successful, amount: $payment->amount),
        );
        $provider->shouldReceive('transactionHistory')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $paid = $this->callbackBody($payment, ['status' => 'berhasil', 'status_kode' => 1]);
        $this->postCallback($paid)->assertOk()->assertExactJson(['success' => true]);
        $this->postCallback($paid)->assertOk()->assertExactJson(['success' => true]);

        $payment->refresh();
        $order = $payment->order->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->order_status);
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Sale)->count());
        $this->assertSame(0, ProductStockItem::query()->where('order_item_id', $orderItem->id)->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->where('source_id', (string) $order->id)
            ->count());

        $review = ManualFulfillmentReview::query()->sole();
        $this->assertSame(ManualFulfillmentReview::REASON_LOCAL_CREDENTIALS_MISSING, $review->reason);
        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $review->state);
        $this->assertSame($payment->id, $review->payment_id);

        $paidEffect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PAID)
            ->where('source_type', Order::class)
            ->where('source_id', (string) $order->id)
            ->sole();
        $reviewEffect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)
            ->where('source_type', ManualFulfillmentReview::class)
            ->where('source_id', (string) $review->id)
            ->sole();
        $this->assertNotSame($paidEffect->id, $reviewEffect->id);
        $this->assertNotSame($paidEffect->effect_key, $reviewEffect->effect_key);
        $this->assertSame(TelegramOutboundEffectState::Pending, $paidEffect->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $reviewEffect->state);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectType::AdminOrderReviewNotification->value)
            ->count());

        Queue::assertPushed(
            SendAdminOrderPaidNotification::class,
            fn (SendAdminOrderPaidNotification $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === $paidEffect->id,
        );
        Queue::assertPushed(
            SendAdminOrderPaidNotification::class,
            fn (SendAdminOrderPaidNotification $job): bool => $job->effectId === $reviewEffect->id,
        );
    }

    private function payment(array $attributes = [], bool $managed = true): Payment
    {
        $amount = (int) ($attributes['amount'] ?? 12000);
        $customer = Customer::factory()->create([
            'phone_number' => '08123456789',
            'email' => 'buyer+'.bin2hex(random_bytes(4)).'@example.test',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 1,
        ]);
        $orderItem = OrderItem::factory()->for($order, 'order')->for($product, 'product')->create([
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
        ]);
        if ($managed) {
            // Checkout allocates a real Product Stock Item for managed stock;
            // mirror that here so an ordinary paid callback is not also a
            // missing-credential review case.
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $orderItem->id,
                'secret' => 'sakurupiah-fixture@example.test|password',
            ]);
        }
        InventoryMovement::create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation,
            'quantity' => 1,
            'notes' => 'Order reservation',
            'created_at' => now(),
        ]);

        return Payment::factory()->for($order, 'order')->create(array_merge([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => 'INV-413-'.$order->id,
            'provider_reference' => 'trx-413-'.$order->id,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => $amount + 84,
            'provider_fee' => 84,
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ], $attributes));
    }

    private function providerStatus(
        Payment $payment,
        SakurupiahTransactionStatus $status,
        ?int $amount = null,
        ?string $currency = null,
    ): SakurupiahStatus {
        $data = [
            'trx_id' => $payment->provider_reference,
            'merchant_ref' => $payment->merchant_ref,
            'payment_kode' => SakurupiahCreateInvoice::METHOD_QRISC,
            'payment_status' => $status->value,
        ];
        if ($amount !== null) {
            $data['amount'] = (string) $amount;
        }
        if ($currency !== null) {
            $data['currency'] = $currency;
        }

        return new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: $status,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: $amount === null ? null : SakurupiahAmount::from($amount),
            currency: $currency,
            sanitizedPayload: ['status' => '200', 'data' => [$data]],
        );
    }

    private function callbackBody(Payment $payment, array $overrides = []): string
    {
        return json_encode(array_replace([
            'trx_id' => $payment->provider_reference,
            'merchant_ref' => $payment->merchant_ref,
            'status' => 'pending',
            'status_kode' => 0,
        ], $overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function postCallback(string $body, ?string $signature = null, string $event = SakurupiahCallback::EVENT_PAYMENT_STATUS)
    {
        return $this->call('POST', '/api/webhook/sakurupiah', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_EVENT' => $event,
            'HTTP_X_CALLBACK_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'secret-key'),
        ], $body);
    }
}
