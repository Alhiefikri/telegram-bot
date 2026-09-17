<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Storefront\StorefrontShopSignedInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SakurupiahStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECT_QRIS = '0002010102125303360540820450.005802ID63045B6D';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
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
    }

    public function test_direct_qris_invoice_uses_signed_png_endpoint_and_safe_sakurupiah_copy(): void
    {
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-DIRECT',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => self::DIRECT_QRIS,
                'checkout_url' => null,
            ],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertTrue($invoice->isQrisPayable);
        $this->assertSame('Rp20.450', $invoice->payableAmountDisplay);
        $this->assertNotNull($invoice->qrisImageSource);
        $this->assertStringContainsString('/qris', $invoice->qrisImageSource);
        $this->assertNull($invoice->hostedPaymentUrl);
        $this->assertSame($payment->expired_at->toIso8601String(), $invoice->qrisEffectiveExpiresAt?->toIso8601String());

        $html = $this->get($invoice->redirectUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Pembayaran QRIS', $html);
        $this->assertStringContainsString('Invoice QRIS', $html);
        $this->assertStringContainsString('Bayar total QRIS final pada invoice', $html);
        $this->assertStringContainsString('data-native-qris-crop="top-square"', $html);
        $this->assertStringNotContainsString(self::DIRECT_QRIS, $html);
        $this->assertStringNotContainsString('secret-provider-value', $html);

        $image = $this->get($invoice->qrisImageSource)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame("\x89PNG\r\n\x1A\n", substr($image->getContent(), 0, 8));
    }

    public function test_hosted_invoice_exposes_only_the_allowlisted_sakurupiah_payment_page(): void
    {
        [$order] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-HOSTED',
            'provider_presentation' => [
                'mode' => 'hosted',
                'qris_payload' => null,
                'checkout_url' => 'https://sakurupiah.id/pay/invoice',
            ],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertTrue($invoice->isQrisPayable);
        $this->assertNull($invoice->qrisImageSource);
        $this->assertSame('https://sakurupiah.id/pay/invoice', $invoice->hostedPaymentUrl);

        $html = $this->get($invoice->redirectUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Invoice QRIS', $html);
        $this->assertStringContainsString('https://sakurupiah.id/pay/invoice', $html);
        $this->assertStringContainsString('Bayar via Website', $html);
        $this->assertStringNotContainsString(self::DIRECT_QRIS, $html);
        $this->assertStringNotContainsString('secret-provider-value', $html);
    }

    public function test_qr_image_invoice_is_payable_and_served_through_the_signed_endpoint(): void
    {
        $imageUrl = 'https://sakurupiah.id/qris/trx-storefront-qr-image.png';
        Http::fake([
            $imageUrl => Http::response("\x89PNG\r\n\x1A\n".'provider-qr-image', 200, ['Content-Type' => 'image/png']),
        ]);
        [$order] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-QR-IMAGE',
            'provider_presentation' => [
                'mode' => 'qris_image',
                'qris_image_url' => $imageUrl,
                'checkout_url' => null,
            ],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertTrue($invoice->isQrisPayable);
        $this->assertNotNull($invoice->qrisImageSource);
        $this->assertStringContainsString('/qris', $invoice->qrisImageSource);
        $this->assertNull($invoice->hostedPaymentUrl);

        $html = $this->get($invoice->redirectUrl)->assertOk()->getContent();
        $this->assertStringContainsString('data-native-qris-crop="top-square"', $html);
        $this->assertStringNotContainsString($imageUrl, $html);
        $this->assertStringNotContainsString('secret-provider-value', $html);

        $image = $this->get($invoice->qrisImageSource)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame("\x89PNG\r\n\x1A\n", substr($image->getContent(), 0, 8));
    }

    public function test_conflicting_presentation_modes_fail_closed(): void
    {
        [$order] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-INVALID',
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => 'provider-opaque-qr-content',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice',
            ],
        ]);

        $invoice = app(StorefrontShopSignedInvoice::class)->read($order);

        $this->assertFalse($invoice->isQrisPayable);
        $this->assertNull($invoice->qrisImageSource);
        $this->assertNull($invoice->hostedPaymentUrl);
        $this->assertSame('provider_presentation_pending', $invoice->qrisReadinessState);

        $html = $this->get($invoice->redirectUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Tampilan pembayaran belum tersedia', $html);
        $this->assertStringContainsString('QR/link pembayaran yang aman belum tersedia', $html);
        $this->assertStringNotContainsString('provider-opaque-qr-content', $html);
        $this->assertStringNotContainsString('https://sakurupiah.id/pay/invoice', $html);
        $this->assertStringNotContainsString('secret-provider-value', $html);
    }

    public function test_status_refresh_recovers_the_same_sakurupiah_reference_once(): void
    {
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-RECOVERY',
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
            'provider_presentation' => null,
        ]);
        $status = new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: SakurupiahAmount::from(20_000),
            occurredAt: CarbonImmutable::now()->subMinute(),
            expiresAt: $payment->expired_at->toImmutable(),
            sanitizedPayload: [
                'data' => [[
                    'trx_id' => $payment->provider_reference,
                    'merchant_ref' => $payment->merchant_ref,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'total' => '20450',
                    'fee' => '450',
                    'checkout_url' => 'https://sakurupiah.id/pay/invoice',
                ]],
            ],
            total: SakurupiahAmount::from(20_450),
            fee: SakurupiahFee::from(450),
            checkoutUrl: 'https://sakurupiah.id/pay/invoice',
        );
        $provider = $this->mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn($status);

        $response = $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk();

        $response->assertJsonPath('qris_ready', true);
        $response->assertJsonPath('should_reload', true);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', 'reconciliation')->count());
    }

    #[DataProvider('presentationPendingStates')]
    public function test_status_refresh_retries_sakurupiah_presentation_pending_without_creating_again(string $providerCreateState): void
    {
        [$order, $payment] = $this->payment([
            'invoice_number' => 'INV-SAK-STOREFRONT-PRESENTATION-RETRY',
            'provider_create_state' => $providerCreateState,
            'provider_presentation' => [
                'mode' => 'qris',
                'qris_payload' => 'stale-provider-qr-content',
                'checkout_url' => 'https://sakurupiah.id/pay/invoice?token=redacted',
            ],
        ]);
        $status = new SakurupiahStatus(
            trxId: (string) $payment->provider_reference,
            status: SakurupiahTransactionStatus::Pending,
            merchantRef: (string) $payment->merchant_ref,
            paymentCode: SakurupiahCreateInvoice::METHOD_QRISC,
            amount: SakurupiahAmount::from(20_000),
            occurredAt: CarbonImmutable::now()->subMinute(),
            expiresAt: $payment->expired_at->toImmutable(),
            sanitizedPayload: [
                'data' => [[
                    'trx_id' => $payment->provider_reference,
                    'merchant_ref' => $payment->merchant_ref,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'total' => '20450',
                    'fee' => '450',
                    'qr' => self::DIRECT_QRIS,
                ]],
            ],
            total: SakurupiahAmount::from(20_450),
            fee: SakurupiahFee::from(450),
            qr: self::DIRECT_QRIS,
            currency: 'IDR',
        );
        $provider = $this->mock(SakurupiahProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->provider_reference)->andReturn($status);

        $response = $this->postJson(URL::signedRoute('storefront.shop.orders.status', $order, null, false))
            ->assertOk();

        $response->assertJsonPath('qris_ready', true);
        $response->assertJsonPath('should_reload', true);
        $this->assertSame(SakurupiahInvoiceLifecycleService::STATE_CREATED, $payment->fresh()->provider_create_state);
        $this->assertSame(1, $payment->fresh()->paymentEvents()->where('event_type', 'reconciliation')->count());
    }

    public static function presentationPendingStates(): array
    {
        return [
            'provider state' => [SakurupiahInvoiceLifecycleService::STATE_PROVIDER_QRIS_UNUSABLE],
            'invalid persisted snapshot' => [SakurupiahInvoiceLifecycleService::STATE_CREATED],
        ];
    }

    /** @return array{0: Order, 1: Payment} */
    private function payment(array $attributes = []): array
    {
        $expiresAt = now()->addMinutes(20)->startOfSecond();
        $invoiceNumber = $attributes['invoice_number'] ?? 'INV-SAK-STOREFRONT';
        $presentation = $attributes['provider_presentation'] ?? [
            'mode' => 'hosted',
            'qris_payload' => null,
            'checkout_url' => 'https://sakurupiah.id/pay/invoice',
        ];
        unset($attributes['invoice_number'], $attributes['provider_presentation']);
        if (is_array($presentation)) {
            $presentation['effective_expires_at'] = $expiresAt->toIso8601String();
        }

        $customer = Customer::factory()->create(['first_name' => 'Storefront Buyer']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => $invoiceNumber,
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'total_amount' => 20_000,
            'currency' => 'IDR',
            'expires_at' => $expiresAt,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_name' => 'Premium access',
            'quantity' => 1,
            'unit_price' => 20_000,
            'subtotal' => 20_000,
        ]);

        $payment = Payment::factory()->for($order)->create(array_merge([
            'provider' => 'sakurupiah',
            'method' => SakurupiahCreateInvoice::PAYMENT_METHOD_QRIS,
            'merchant_ref' => $invoiceNumber,
            'provider_reference' => 'trx-'.$invoiceNumber,
            'provider_payment_method' => SakurupiahCreateInvoice::METHOD_QRISC,
            'provider_total_payment' => 20_450,
            'provider_fee' => 450,
            'provider_created_at' => now()->subMinute(),
            'provider_expires_at' => $expiresAt,
            'provider_presentation' => $presentation,
            'provider_payload' => [
                'data' => [[
                    'trx_id' => 'trx-'.$invoiceNumber,
                    'merchant_ref' => $invoiceNumber,
                    'payment_kode' => 'QRISC',
                    'payment_status' => 'pending',
                    'total' => '20450',
                    'fee' => '450',
                    'signature' => 'secret-provider-value',
                ]],
            ],
            'provider_create_state' => SakurupiahInvoiceLifecycleService::STATE_CREATED,
            'amount' => 20_000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Pending,
            'expired_at' => $expiresAt,
        ], $attributes));

        return [$order, $payment];
    }
}
