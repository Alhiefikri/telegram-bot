<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahCreatePreRequestException;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahPaymentEventLogger;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahSchemaException;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SakurupiahInvoiceLifecycleTest extends TestCase
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
            'lock_lease_seconds' => 90,
        ]));
    }

    public function test_success_persists_typed_snapshot_and_returns_only_safe_payable_data(): void
    {
        $payment = $this->payment();
        $invoice = $this->invoice($payment);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertNotNull($result->payable());
        $this->assertSame('trx-411', $result->payable()?->providerReference());
        $this->assertSame(12084, $result->payable()?->totalAmount());
        $this->assertSame('https://sakurupiah.id/pay/invoice', $result->payable()?->checkoutUrl());

        $payment->refresh();
        $this->assertSame('trx-411', $payment->provider_reference);
        $this->assertSame('QRISC', $payment->provider_payment_method);
        $this->assertSame(12084, $payment->provider_total_payment);
        $this->assertSame(84, $payment->provider_fee);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->provider_create_state);
        $this->assertArrayNotHasKey('checkout_url', $payment->provider_payload['data'][0]);

        $event = $payment->paymentEvents()->sole();
        $this->assertSame(PaymentProvider::SAKURUPIAH, $event->provider);
        $this->assertSame(1, $event->received_count);
        $this->assertArrayNotHasKey('checkout_url', $event->raw_payload['data'][0]);

        // A checkout reuse sees the persisted encrypted presentation and
        // never creates a second provider invoice or Payment.
        $second = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $second->outcome());
        $this->assertSame(12084, $second->payable()?->totalAmount());
        $this->assertSame($result->payable()?->effectiveExpiresAt()->toIso8601String(), $second->payable()?->effectiveExpiresAt()->toIso8601String());
        $this->assertArrayNotHasKey('provider_presentation', $payment->toArray());
        $this->assertSame(1, Payment::query()->where('order_id', $payment->order_id)->count());
    }

    public function test_qris_emv_crc_currency_and_exact_amount_are_required_for_payable(): void
    {
        $payment = $this->payment(['amount' => 20000]);
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-qris',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 20450,
            amount: 20000,
            fee: SakurupiahFee::from(450),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            qr: '0002010102125303360540820450.005802ID63045B6D',
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame('0002010102125303360540820450.005802ID63045B6D', $result->payable()?->qrisPayload());
        $this->assertTrue($result->payable()?->isDirectQris());
        $this->assertNull($result->payable()?->checkoutUrl());
    }

    public function test_query_bearing_hosted_url_never_becomes_payable(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-QUERY']);
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-query',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 12084,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice?source=provider',
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);
        $this->assertSame(SakurupiahInvoiceOutcome::ProviderPresentationPending, $result->outcome());
        $this->assertNull($result->payable());
        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_PROVIDER_QRIS_UNUSABLE, $fresh->provider_create_state);
        $this->assertSame('provider_presentation_unusable', $fresh->provider_create_last_error);
        $this->assertArrayNotHasKey('checkout_url', $fresh->provider_payload);
    }

    public function test_http_create_response_is_parsed_and_sent_once_after_local_payment_exists(): void
    {
        $this->travelTo('2026-09-02 07:00:00');
        $payment = $this->payment();
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'trx_id' => 'trx-411',
                'merchant_ref' => $payment->merchant_ref,
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
                'amount' => '12000',
                'total' => '12084',
                'fee' => '84',
                'date' => '2026-09-02',
                'time' => '12:00:00',
                'expired' => '2026-09-03 12:00:00',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice',
            ]],
        ], 200));

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/create.php')
            && $request['method'] === 'QRISC'
            && $request['merchant_ref'] === $payment->merchant_ref
            && $request['amount'] === '12000');
    }

    public function test_create_transport_failure_is_unknown_and_not_retried(): void
    {
        $payment = $this->payment();
        $attempts = 0;
        Http::fake(function () use (&$attempts): never {
            $attempts++;
            throw new ConnectionException('transport failure');
        });

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $first = $lifecycle->lifecycle($payment);
        $second = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $first->outcome());
        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $second->outcome());
        $this->assertSame(1, $attempts);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, $payment->fresh()->provider_create_state);
    }

    public function test_create_5xx_is_recovery_pending_and_not_retried(): void
    {
        $payment = $this->payment();
        Http::fake(fn () => Http::response(['status' => '503'], 503));

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $result->outcome());
        Http::assertSentCount(1);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN, $payment->fresh()->provider_create_state);
    }

    public function test_malformed_create_response_is_recovery_pending_without_second_create(): void
    {
        $payment = $this->payment();
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'trx_id' => 'trx-411',
                'merchant_ref' => $payment->merchant_ref,
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
            ]],
        ], 200));

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $result = $lifecycle->lifecycle($payment);
        $again = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $result->outcome());
        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $again->outcome());
        Http::assertSentCount(1);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, $payment->fresh()->provider_create_state);
    }

    public function test_readiness_failure_is_retryable_without_sending_create(): void
    {
        $payment = $this->payment();
        config()->set('services.sakurupiah.sandbox_api_key', null);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        try {
            app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);
            $this->fail('Expected pre-request readiness failure.');
        } catch (SakurupiahCreatePreRequestException) {
            $this->addToAssertionCount(1);
        }

        $payment->refresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED, $payment->provider_create_state);
        $this->assertSame(1, $payment->paymentEvents()->count());

        config()->set('services.sakurupiah.sandbox_api_key', 'secret-key');
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment));

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame(1, Payment::query()->where('order_id', $payment->order_id)->count());
    }

    public function test_ambiguous_create_is_recovery_pending_and_never_recreated(): void
    {
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')
            ->once()
            ->andThrow(new SakurupiahSchemaException('createInvoice', outcomeUnknown: true));
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $first = $lifecycle->lifecycle($payment);
        $second = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $first->outcome());
        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $second->outcome());
        $payment->refresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, $payment->provider_create_state);
        $this->assertSame(1, Payment::query()->where('order_id', $payment->order_id)->count());
    }

    public function test_status_recovery_uses_same_reference_and_can_become_payable(): void
    {
        $payment = $this->payment([
            'provider_reference' => 'trx-411',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
        ]);
        $status = new SakurupiahStatus(
            trxId: 'trx-411',
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: 'QRISC',
            amount: SakurupiahAmount::from(12000),
            occurredAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            sanitizedPayload: [
                'status' => '200',
                'data' => [[
                    'trx_id' => 'trx-411',
                    'merchant_ref' => $payment->merchant_ref,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'amount' => '12000',
                    'total' => '12084',
                    'fee' => '84',
                    'checkout_url' => 'https://sakurupiah.id/pay/invoice',
                ]],
            ],
            total: SakurupiahAmount::from(12084),
            fee: SakurupiahFee::from(84),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with('trx-411')->andReturn($status);
        $provider->shouldReceive('createInvoice')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->refreshStatus($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame('trx-411', $result->payable()?->providerReference());
        $payment->refresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->provider_create_state);
        $this->assertSame(1, $payment->paymentEvents()->where('event_type', 'reconciliation')->count());
    }

    public function test_pending_status_without_presentation_reuses_existing_validated_snapshot(): void
    {
        $payment = $this->payment();
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment));
        $provider->shouldReceive('transactionStatus')->once()->with('trx-411')->andReturn(new SakurupiahStatus(
            trxId: 'trx-411',
            status: SakurupiahTransactionStatus::Pending,
            sanitizedPayload: [
                'status' => '200',
                'data' => [['trx_id' => 'trx-411', 'payment_status' => 'pending']],
            ],
        ));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $created = $lifecycle->lifecycle($payment);
        $refreshed = $lifecycle->refreshStatus($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $created->outcome());
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $refreshed->outcome());
        $this->assertSame('https://sakurupiah.id/pay/invoice', $refreshed->payable()?->checkoutUrl());
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertSame('hosted', $payment->fresh()->provider_presentation['mode']);
    }

    public function test_provider_total_mismatch_is_never_payable(): void
    {
        $payment = $this->payment();
        $invoice = $this->invoice($payment, total: 11999);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($result->payable());
        $payment->refresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_PROVIDER_TOTAL_INVALID, $payment->provider_create_state);
        $this->assertSame('trx-411', $payment->provider_reference);
    }

    public function test_expired_provider_deadline_is_never_payable(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-EXPIRED']);
        $invoice = $this->invoice($payment, expiresAt: CarbonImmutable::now()->subMinute());
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Expired, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_PROVIDER_EXPIRED, $payment->fresh()->provider_create_state);
    }

    public function test_provider_fee_payer_mismatch_is_never_payable(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-FEE-PAYER']);
        $invoice = $this->invoice($payment, merchantFee: 2);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_PROVIDER_TOTAL_INVALID, $payment->fresh()->provider_create_state);
    }

    public function test_single_flight_lock_is_scoped_to_the_payment(): void
    {
        $firstPayment = $this->payment();
        $secondPayment = $this->payment(['merchant_ref' => 'INV-412']);
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $firstLock = Cache::lock($lifecycle->paymentLockKey($firstPayment), 90);

        $this->assertTrue($firstLock->get());
        try {
            $this->assertNull($lifecycle->tryWithPaymentLock($firstPayment, static fn (): string => 'busy'));
            $this->assertSame('free', $lifecycle->tryWithPaymentLock($secondPayment, static fn (): string => 'free'));
        } finally {
            $firstLock->release();
        }
    }

    public function test_repeated_observation_is_idempotent_and_increments_count(): void
    {
        $payment = $this->payment([
            'provider_reference' => 'trx-411',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
        ]);
        $logger = app(SakurupiahPaymentEventLogger::class);

        $logger->logRecoveryObservation($payment, SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, 'status_unavailable');
        $logger->logRecoveryObservation($payment, SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, 'status_unavailable');

        $event = $payment->paymentEvents()->sole();
        $this->assertSame(2, $event->received_count);
        $this->assertSame(['status' => SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED, 'message' => 'status_unavailable'], $event->raw_payload);
    }

    private function payment(array $attributes = []): Payment
    {
        $amount = (int) ($attributes['amount'] ?? 12000);
        $customer = Customer::factory()->create([
            'phone_number' => '08123456789',
            'email' => 'buyer+'.bin2hex(random_bytes(4)).'@example.test',
        ]);
        $order = Order::factory()->for($customer, 'customer')->create([
            'total_amount' => $amount,
            'currency' => 'IDR',
            'expires_at' => now()->addHours(2),
        ]);

        return Payment::factory()->for($order, 'order')->create(array_merge([
            'provider' => PaymentProvider::SAKURUPIAH,
            'merchant_ref' => 'INV-411',
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'amount' => $amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => now()->addHours(2),
        ], $attributes));
    }

    private function invoice(
        Payment $payment,
        int $total = 12084,
        ?CarbonImmutable $expiresAt = null,
        ?int $merchantFee = null,
    ): SakurupiahInvoice {
        return new SakurupiahInvoice(
            trxId: 'trx-411',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: $total,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: $expiresAt ?? CarbonImmutable::now()->addDay(),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
            sanitizedPayload: [
                'status' => '200',
                'data' => [[
                    'trx_id' => 'trx-411',
                    'merchant_ref' => $payment->merchant_ref,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'amount' => '12000',
                    'total' => (string) $total,
                    'fee' => '84',
                    'checkout_url' => 'https://sakurupiah.id/pay/invoice',
                ]],
            ],
            merchantFee: $merchantFee,
        );
    }
}
