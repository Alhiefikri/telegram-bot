<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Services\StoreBalance\TopUp\WijayaPayStoreBalanceTopUpLifecycle;
use App\Services\WijayaPay\WijayaPayCreateInvoice;
use App\Services\WijayaPay\WijayaPayInvoice;
use App\Services\WijayaPay\WijayaPayProviderAdapter;
use App\Services\WijayaPay\WijayaPayProviderException;
use App\Services\WijayaPay\WijayaPayStatus;
use App\Services\WijayaPay\WijayaPayStatusResponse;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WijayaPayTopUpRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.wijayapay', array_replace(config('services.wijayapay', []), [
            'base_url' => 'https://gateway.wijayapay.com',
            'code_merchant' => 'WP-test-merchant',
            'api_key' => 'test-api-key',
            'callback_url' => 'https://store.example.test/api/webhook/wijayapay',
            'return_url' => 'https://store.example.test/shop/orders',
            'allowed_hosts' => ['gateway.wijayapay.com', 'wijayapay.com'],
            'callback_allowed_hosts' => ['store.example.test'],
            'return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'max_fee_bps' => 500,
                'expiry_confirmed' => true,
            ],
        ]));
    }

    public function test_payable_top_up_builds_a_validated_qris_attachment(): void
    {
        Http::fake([
            'wijayapay.com/qris/*' => Http::response("\x89PNG\r\n\x1A\n".str_repeat('x', 64), 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);
        $topUp = $this->payableTopUp();
        $rail = app(WijayaPayStoreBalanceTopUpLifecycle::class);

        $classified = $rail->classify($topUp);
        $this->assertTrue($classified->isPayable());
        $this->assertSame(12_000, $classified->payable()?->creditAmount());
        $this->assertSame(12_084, $classified->payable()?->totalAmount());

        $attachment = $rail->instructionAttachment($topUp, $classified->payable());
        $this->assertStringStartsWith("\x89PNG", $attachment->photoContents());
    }

    public function test_paid_status_returns_trusted_settlement_evidence(): void
    {
        $topUp = $this->payableTopUp();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with((string) $topUp->merchant_ref)->andReturn(
            new WijayaPayStatusResponse(
                refId: (string) $topUp->merchant_ref,
                trxReference: (string) $topUp->provider_reference,
                paymentMethod: 'QRIS',
                status: WijayaPayStatus::Paid,
                totalBayar: 12_084,
                totalDibayar: 12_084,
                totalFee: 84,
                amountReceived: 12_000,
                expiresAt: CarbonImmutable::now()->addMinutes(10),
                updatedAt: CarbonImmutable::now(),
                sanitizedPayload: ['status_pembayaran' => 'paid'],
            ),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayStoreBalanceTopUpLifecycle::class)->reconcile($topUp);

        $this->assertTrue($result->hasEvidence());
        $this->assertTrue($result->evidence()->isTrusted());
        $this->assertSame(PaymentProvider::WIJAYAPAY, $result->evidence()->provider());
        $this->assertSame(12_000, $result->evidence()->amount());
        $this->assertSame(12_084, $result->evidence()->totalAmount());
    }

    public function test_not_found_ambiguous_top_up_retries_the_same_reference(): void
    {
        $topUp = $this->payableTopUp();
        $topUp->forceFill([
            'provider_reference' => null,
            'provider_fee' => null,
            'provider_total_payment' => null,
            'provider_qris_url' => null,
            'provider_create_state' => 'outcome_unknown',
            'provider_create_attempted_at' => now()->subMinutes(2),
            'status' => StoreBalanceTopUpStatus::Pending,
        ])->save();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andThrow(new WijayaPayProviderException(
            'WijayaPay transactionStatus request failed.',
            operation: 'transactionStatus',
            providerMessage: 'Transaksi tidak ditemukan',
            reason: 'application_rejection',
        ));
        $provider->shouldReceive('createInvoice')->once()->andReturn(new WijayaPayInvoice(
            refId: (string) $topUp->merchant_ref,
            trxReference: 'WPTRX-RECOVERED',
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            status: WijayaPayStatus::Pending,
            totalBayar: 12_084,
            totalFee: 84,
            totalDiterima: 12_000,
            feePayer: 'customer',
            expiresAt: CarbonImmutable::now()->addMinutes(10),
            updatedAt: CarbonImmutable::now(),
            qrString: null,
            qrImageUrl: 'https://wijayapay.com/qris/topup-recovered.png',
            sanitizedPayload: ['status_pembayaran' => 'pending'],
        ));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayStoreBalanceTopUpLifecycle::class)->reconcile($topUp);

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame('WPTRX-RECOVERED', $topUp->fresh()->provider_reference);
        $this->assertSame(12_084, $topUp->fresh()->provider_total_payment);
        $this->assertSame(84, $topUp->fresh()->provider_fee);
    }

    public function test_ambiguous_create_paid_status_persists_snapshot_before_evidence(): void
    {
        $topUp = $this->payableTopUp();
        $topUp->forceFill([
            'provider_reference' => null,
            'provider_fee' => null,
            'provider_total_payment' => null,
            'provider_qris_url' => null,
            'provider_create_state' => 'outcome_unknown',
            'status' => StoreBalanceTopUpStatus::Pending,
        ])->save();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with((string) $topUp->merchant_ref)->andReturn(
            new WijayaPayStatusResponse(
                refId: (string) $topUp->merchant_ref,
                trxReference: 'WPTRX-TOPUP-AMBIGUOUS',
                paymentMethod: 'QRIS',
                status: WijayaPayStatus::Paid,
                totalBayar: 12_500,
                totalDibayar: 12_500,
                totalFee: 500,
                amountReceived: 12_000,
                expiresAt: CarbonImmutable::now()->addMinutes(10),
                updatedAt: CarbonImmutable::now(),
                sanitizedPayload: ['status_pembayaran' => 'paid'],
            ),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayStoreBalanceTopUpLifecycle::class)->reconcile($topUp);

        $this->assertTrue($result->hasEvidence());
        $this->assertSame(12_000, $result->evidence()->amount());
        $this->assertSame(12_500, $result->evidence()->totalAmount());
        $this->assertSame(12_500, $topUp->fresh()->provider_total_payment);
        $this->assertSame(500, $topUp->fresh()->provider_fee);
        $this->assertSame('WPTRX-TOPUP-AMBIGUOUS', $topUp->fresh()->provider_reference);
    }

    public function test_expired_status_marks_the_top_up_expired(): void
    {
        $topUp = $this->payableTopUp();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with((string) $topUp->merchant_ref)->andReturn(
            new WijayaPayStatusResponse(
                refId: (string) $topUp->merchant_ref,
                trxReference: (string) $topUp->provider_reference,
                paymentMethod: 'QRIS',
                status: WijayaPayStatus::Expired,
                totalBayar: 12_084,
                totalDibayar: null,
                totalFee: 84,
                amountReceived: null,
                expiresAt: CarbonImmutable::now()->subMinute(),
                updatedAt: CarbonImmutable::now(),
                sanitizedPayload: ['status_pembayaran' => 'expired'],
            ),
        );
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $result = app(WijayaPayStoreBalanceTopUpLifecycle::class)->reconcile($topUp);

        $this->assertSame(StoreBalanceTopUpStatus::Expired, $topUp->fresh()->status);
        $this->assertSame('provider_expired', $topUp->fresh()->provider_create_state);
    }

    private function payableTopUp(): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create();
        $expiresAt = now()->addMinutes(15);
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'chat_id' => '70001',
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 12_000,
            'nonce' => 'wijayapay-top-up-'.bin2hex(random_bytes(8)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => '70001',
            'canonical_message_id' => 1,
            'locale' => 'id',
            'provider' => PaymentProvider::WIJAYAPAY,
            'merchant_ref' => 'BAL-WP-'.bin2hex(random_bytes(8)),
            'idempotency_key' => 'wijayapay-top-up-'.bin2hex(random_bytes(8)),
            'currency' => 'IDR',
            'amount' => 12_000,
            'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => 'WPTRX-TOPUP-1',
            'provider_payload' => ['status_pembayaran' => 'pending'],
            'provider_qris_url' => 'https://wijayapay.com/qris/topup-test.png',
            'provider_fee' => 84,
            'provider_total_payment' => 12_084,
            'provider_payment_method' => 'QRIS',
            'provider_created_at' => now()->subMinute(),
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_create_state' => 'created',
            'provider_create_attempted_at' => now()->subMinute(),
        ]);
    }
}
