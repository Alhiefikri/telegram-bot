<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahApplicationException;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_qris_create_uses_owned_channel_after_setting_changes_and_recovers_without_a_channel_echo(): void
    {
        $setting = ManualPaymentSetting::create(['payment_mode' => PaymentProvider::SAKURUPIAH, 'sakurupiah_channel' => 'QRIS']);
        $payment = $this->payment(['sakurupiah_channel' => 'QRIS']);
        $invoice = $this->invoice($payment, channel: 'QRIS');
        $setting->update(['sakurupiah_channel' => 'QRISC']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->withArgs(
            fn (SakurupiahCreateInvoice $request): bool => $request->method === 'QRIS' && $request->expired === 24,
        )->andReturn($invoice);
        $provider->shouldReceive('transactionStatus')->once()->with('trx-411')->andReturn(new SakurupiahStatus(
            trxId: 'trx-411',
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            amount: SakurupiahAmount::from(12000),
            total: SakurupiahAmount::from(12084),
            fee: SakurupiahFee::from(84),
            expiresAt: CarbonImmutable::now()->addHour(),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
        ));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);

        $created = $lifecycle->lifecycle($payment);
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $created->outcome());
        $this->assertSame('QRIS', $created->payable()?->paymentMethod());
        $setting->update(['sakurupiah_channel' => 'QRIS2']);
        $recovered = $lifecycle->refreshStatus($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $recovered->outcome());
        $this->assertSame('QRIS', $recovered->payable()?->paymentMethod());
        $this->assertSame('QRIS', $payment->fresh()->provider_payment_method);
        $this->assertSame('QRIS', $payment->fresh()->sakurupiah_channel);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_historical_qrisc_payment_remains_payable_after_owner_switches_to_qris(): void
    {
        ManualPaymentSetting::create(['payment_mode' => PaymentProvider::SAKURUPIAH, 'sakurupiah_channel' => 'QRIS']);
        $payment = $this->payment(['sakurupiah_channel' => null]);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->withArgs(
            fn (SakurupiahCreateInvoice $request): bool => $request->method === 'QRISC',
        )->andReturn($this->invoice($payment));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);

        $this->assertSame('QRISC', $lifecycle->lifecycle($payment)->payable()?->paymentMethod());
        $this->assertSame('QRISC', $lifecycle->classify($payment)->payable()?->paymentMethod());
    }

    public function test_create_response_for_another_supported_channel_is_not_payable(): void
    {
        $payment = $this->payment(['sakurupiah_channel' => 'QRIS']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment, channel: 'QRISC'));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertNull($payment->fresh()->provider_reference);
        $this->assertSame('QRIS', $payment->fresh()->sakurupiah_channel);
    }

    public function test_persisted_presentation_rejects_another_supported_channel(): void
    {
        $payment = $this->payment(['sakurupiah_channel' => 'QRIS']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($this->invoice($payment, channel: 'QRIS'));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $lifecycle->lifecycle($payment)->outcome());
        $payment->update(['provider_payment_method' => 'QRIS2']);

        $this->assertSame(SakurupiahInvoiceOutcome::InvalidProviderResult, $lifecycle->classify($payment)->outcome());
        $this->assertNull($lifecycle->classify($payment)->payable());
    }

    public function test_ambiguous_qris_create_never_retries_or_falls_back_after_owner_switches_channel(): void
    {
        $setting = ManualPaymentSetting::create(['payment_mode' => PaymentProvider::SAKURUPIAH, 'sakurupiah_channel' => 'QRIS']);
        $payment = $this->payment(['sakurupiah_channel' => 'QRIS']);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->withArgs(
            fn (SakurupiahCreateInvoice $request): bool => $request->method === 'QRIS',
        )->andThrow(new ConnectionException('transport failure'));
        $provider->shouldReceive('transactionStatus')->never();
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $lifecycle->lifecycle($payment)->outcome());
        $setting->update(['sakurupiah_channel' => 'QRISC']);
        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $lifecycle->lifecycle($payment)->outcome());
        $this->assertSame('QRIS', $payment->fresh()->sakurupiah_channel);
        $this->assertSame('INV-411', $payment->fresh()->merchant_ref);
        $this->assertNull($payment->fresh()->provider_reference);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_status_recovery_rejects_a_different_supported_channel(): void
    {
        $payment = $this->payment([
            'sakurupiah_channel' => 'QRIS',
            'provider_reference' => 'trx-411',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
        ]);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->never();
        $provider->shouldReceive('transactionStatus')->once()->andReturn(new SakurupiahStatus(
            trxId: 'trx-411',
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: 'QRISC',
            amount: SakurupiahAmount::from(12000),
        ));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->refreshStatus($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame('payment_method_mismatch', $payment->fresh()->provider_create_last_error);
        $this->assertSame('QRIS', $payment->fresh()->sakurupiah_channel);
    }

    #[DataProvider('supportedCreateChannels')]
    public function test_create_sends_the_snapshotted_channel_with_its_qualified_expiry(string $channel, int $expiryHours): void
    {
        $payment = $this->payment(['sakurupiah_channel' => $channel]);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->withArgs(
            fn (SakurupiahCreateInvoice $request): bool => $request->method === $channel && $request->expired === $expiryHours,
        )->andReturn($this->invoice($payment, channel: $channel));
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame($channel, $result->payable()?->paymentMethod());
    }

    public static function supportedCreateChannels(): array
    {
        return [
            'QRIS' => ['QRIS', 24],
            'QRISC' => ['QRISC', 24],
            'QRISMU' => ['QRISMU', 12],
            'QRIS2' => ['QRIS2', 24],
        ];
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

    public function test_invalid_provider_qr_falls_back_to_the_allowlisted_checkout_url(): void
    {
        $payment = $this->payment(['amount' => 20000]);
        $invalidQrContent = 'provider-opaque-qr-content-without-terminal-crc';
        $checkoutUrl = 'https://sakurupiah.id/pay/invoice?source=provider';
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
            qr: $invalidQrContent,
            checkoutUrl: $checkoutUrl,
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertNull($result->payable()?->qrisPayload());
        $this->assertTrue($result->payable()?->isHosted());
        $this->assertSame($checkoutUrl, $result->payable()?->checkoutUrl());
        $this->assertSame('hosted', $payment->fresh()->provider_presentation['mode']);
    }

    public function test_query_bearing_provider_hosted_url_becomes_payable(): void
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

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertSame('https://sakurupiah.id/pay/invoice?source=provider', $result->payable()?->checkoutUrl());
        $this->assertTrue($result->payable()?->isHosted());
        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $fresh->provider_create_state);
        $this->assertSame('hosted', $fresh->provider_presentation['mode']);
        $this->assertArrayNotHasKey('checkout_url', $fresh->provider_payload);
        $this->assertStringNotContainsString(
            'source=provider',
            (string) $fresh->getRawOriginal('provider_presentation'),
        );
    }

    public function test_non_allowlisted_hosted_url_remains_unpayable(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-HOST']);
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-host',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 12084,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            checkoutUrl: 'https://payments.example.test/pay?source=provider',
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::ProviderPresentationPending, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_PROVIDER_QRIS_UNUSABLE,
            $payment->fresh()->provider_create_state,
        );
    }

    public function test_provider_qr_image_url_becomes_payable_and_survives_snapshot_rehydration(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-QR-IMAGE']);
        $imageUrl = 'https://sakurupiah.id/qris/trx-411-qr-image.png';
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-qr-image',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 12084,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            qr: $imageUrl,
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $payable = $result->payable();
        $this->assertNotNull($payable);
        $this->assertTrue($payable->isQrisImage());
        $this->assertSame($imageUrl, $payable->qrisImageUrl());
        $this->assertFalse($payable->isDirectQris());
        $this->assertNull($payable->qrisPayload());
        $this->assertNull($payable->checkoutUrl());

        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $fresh->provider_create_state);
        $this->assertSame('qris_image', $fresh->provider_presentation['mode']);
        $this->assertStringNotContainsString(
            'sakurupiah.id/qris',
            (string) $fresh->getRawOriginal('provider_presentation'),
        );

        $classified = app(SakurupiahInvoiceLifecycleService::class)->classify($fresh);
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $classified->outcome());
        $this->assertTrue($classified->payable()?->isQrisImage());
        $this->assertSame($imageUrl, $classified->payable()?->qrisImageUrl());
    }

    public function test_non_allowlisted_provider_qr_image_url_is_never_payable(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-BAD-QR-IMAGE']);
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-bad-qr-image',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 12084,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            qr: 'https://cdn.example.test/qris/trx-411-bad-qr-image.png',
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::ProviderPresentationPending, $result->outcome());
        $this->assertNull($result->payable());
        $this->assertNull($payment->fresh()->provider_presentation);
    }

    public function test_qr_image_presentation_keeps_the_allowlisted_checkout_link(): void
    {
        $payment = $this->payment(['merchant_ref' => 'INV-411-QR-IMAGE-LINK']);
        $imageUrl = 'https://sakurupiah.id/qris/trx-411-qr-image-link.png';
        $checkoutUrl = 'https://sakurupiah.id/pay/invoice?source=provider';
        $invoice = new SakurupiahInvoice(
            trxId: 'trx-411-qr-image-link',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            status: SakurupiahTransactionStatus::Pending,
            total: 12084,
            amount: 12000,
            fee: SakurupiahFee::from(84),
            createdAt: CarbonImmutable::now()->subMinute(),
            expiresAt: CarbonImmutable::now()->addDay(),
            qr: $imageUrl,
            checkoutUrl: $checkoutUrl,
        );
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $result->outcome());
        $this->assertTrue($result->payable()?->isQrisImage());
        $this->assertSame($imageUrl, $result->payable()?->qrisImageUrl());
        $this->assertSame($checkoutUrl, $result->payable()?->checkoutUrl());
        $this->assertSame('qris_image', $payment->fresh()->provider_presentation['mode']);
    }

    public function test_customer_borne_fee_contract_sends_merchant_fee_two_and_keeps_the_payer_across_rehydration(): void
    {
        $this->travelTo('2026-09-02 07:00:00');
        config()->set('services.sakurupiah.qris_contract.fee_payer', 'customer');
        config()->set('services.sakurupiah.qris_contract.merchant_fee', 2);

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
                'merchant_fee' => 'Pelanggan',
                'date' => '2026-09-02',
                'time' => '12:00:00',
                'expired' => '2026-09-03 12:00:00',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice',
            ]],
        ], 200));

        $result = app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);

        $this->assertTrue($result->isPayable());
        $this->assertTrue($result->payable()?->customerPaysFee());
        $this->assertSame(12084, $result->payable()?->totalAmount());
        $this->assertSame(84, $result->payable()?->providerFee());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/create.php')
            && $request['merchant_fee'] === 2);

        $this->assertTrue($payment->fresh()->provider_presentation['customer_pays_fee']);

        // The persisted payer survives rehydration, so the customer copy stays
        // truthful even after the configured policy changes.
        config()->set('services.sakurupiah.qris_contract.fee_payer', 'merchant');
        config()->set('services.sakurupiah.qris_contract.merchant_fee', 1);
        $rehydrated = app(SakurupiahInvoiceLifecycleService::class)->classify($payment->fresh());

        $this->assertTrue($rehydrated->isPayable());
        $this->assertTrue($rehydrated->payable()?->customerPaysFee());
    }

    public function test_inconsistent_fee_payer_contract_blocks_the_create_request(): void
    {
        config()->set('services.sakurupiah.qris_contract.fee_payer', 'customer');
        config()->set('services.sakurupiah.qris_contract.merchant_fee', 1);

        $payment = $this->payment();
        Http::fake();

        try {
            app(SakurupiahInvoiceLifecycleService::class)->lifecycle($payment);
            $this->fail('Expected an inconsistent fee contract to stop the create request.');
        } catch (SakurupiahCreatePreRequestException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();
        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            $payment->fresh()->provider_create_state,
        );
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

    public function test_provider_application_rejection_is_retryable_on_the_same_reference(): void
    {
        $payment = $this->payment();
        $attempts = 0;
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->andReturnUsing(function () use (&$attempts, $payment): SakurupiahInvoice {
            $attempts++;

            if ($attempts === 1) {
                throw new SakurupiahApplicationException(
                    'createInvoice',
                    '400',
                    providerMessage: 'Gagal membuat Invoice. hubungi tim dukungan',
                );
            }

            return $this->invoice($payment);
        });
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $rejected = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Creating, $rejected->outcome());
        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED, $fresh->provider_create_state);
        $this->assertSame(
            SakurupiahApplicationException::class.'|application_status:400|provider_message:Gagal membuat Invoice. hubungi tim dukungan',
            $fresh->provider_create_last_error,
        );
        $this->assertNull($fresh->provider_reference);
        $this->assertSame(1, $attempts);

        // The retry interval keeps one rejected Payment from re-creating on
        // every refresh, and a throttled recovery pass must not downgrade the
        // rejection into an ambiguous state it does not have.
        $throttled = $lifecycle->lifecycle($payment);
        $this->assertSame(SakurupiahInvoiceOutcome::Creating, $throttled->outcome());
        $this->assertSame(1, $attempts);

        $throttledRecovery = $lifecycle->tryRefreshStatus($payment);
        $this->assertNotNull($throttledRecovery);
        $this->assertSame(SakurupiahInvoiceOutcome::Creating, $throttledRecovery->outcome());
        $this->assertSame(1, $attempts);
        $this->assertSame(
            SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            $payment->fresh()->provider_create_state,
        );

        // A customer status refresh retries the same merchant reference once
        // the interval has passed, so the QR arrives without a new checkout.
        $this->travel(61)->seconds();
        $recovered = $lifecycle->tryRefreshStatus($payment);

        $this->assertNotNull($recovered);
        $this->assertTrue($recovered->isPayable());
        $this->assertSame(2, $attempts);
        $this->assertSame('trx-411', $payment->fresh()->provider_reference);
        $this->assertSame(1, Payment::query()->where('order_id', $payment->order_id)->count());

        // The recovered presentation is reused instead of creating again.
        $again = $lifecycle->lifecycle($payment);
        $this->assertTrue($again->isPayable());
        $this->assertSame(2, $attempts);
    }

    public function test_customer_status_refresh_gate_covers_retryable_and_referenced_payments(): void
    {
        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $rejected = $this->payment([
            'merchant_ref' => 'INV-411-A',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATE_FAILED,
            'provider_create_attempted_at' => now(),
        ]);
        $ambiguous = $this->payment([
            'merchant_ref' => 'INV-411-B',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
            'provider_create_attempted_at' => now()->subHour(),
        ]);
        $referenced = $this->payment([
            'merchant_ref' => 'INV-411-C',
            'provider_reference' => 'trx-411',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_PROVIDER_QRIS_UNUSABLE,
        ]);

        $this->assertFalse($lifecycle->isCustomerStatusRefreshable($rejected));
        $this->assertFalse($lifecycle->isCustomerStatusRefreshable($ambiguous));
        $this->assertTrue($lifecycle->isCustomerStatusRefreshable($referenced));

        $this->travel(61)->seconds();

        $this->assertTrue($lifecycle->isCustomerStatusRefreshable($rejected));
    }

    public function test_provider_application_error_status_stays_ambiguous_and_is_not_recreated(): void
    {
        $payment = $this->payment();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['status' => '500', 'message' => 'internal'], 200);
        });

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $first = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $first->outcome());
        $fresh = $payment->fresh();
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN, $fresh->provider_create_state);
        $this->assertSame(
            SakurupiahApplicationException::class.'|application_status:500|provider_message:internal',
            $fresh->provider_create_last_error,
        );

        // A provider 5xx-class status may have partially created the
        // transaction, so it must never be re-created.
        $this->travel(120)->seconds();
        $second = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::RecoveryPending, $second->outcome());
        $this->assertSame(1, $attempts);
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

    public function test_provider_total_below_order_amount_is_never_payable(): void
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

    public function test_merchant_borne_provider_fee_does_not_increase_buyer_total(): void
    {
        $payment = $this->payment();
        $invoice = $this->invoice($payment, total: 12000, merchantFee: 1);
        $provider = Mockery::mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('createInvoice')->once()->andReturn($invoice);
        $this->app->instance(SakurupiahProviderAdapter::class, $provider);

        $lifecycle = app(SakurupiahInvoiceLifecycleService::class);
        $created = $lifecycle->lifecycle($payment);
        $persisted = $lifecycle->lifecycle($payment);

        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $created->outcome());
        $this->assertSame(12000, $created->payable()?->totalAmount());
        $this->assertSame(84, $created->payable()?->providerFee());
        $this->assertSame(SakurupiahInvoiceOutcome::Payable, $persisted->outcome());
        $this->assertSame(12000, $persisted->payable()?->totalAmount());
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
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
        string $channel = SakurupiahCreateInvoice::METHOD_QRISC,
    ): SakurupiahInvoice {
        return new SakurupiahInvoice(
            trxId: 'trx-411',
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: $channel,
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
                    'payment_kode' => $channel,
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
