<?php

namespace Tests\Unit\Services\KlikQris;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisSandboxCheckoutSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class KlikQrisProviderReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_is_ready_only_when_enabled_with_complete_sandbox_configuration_outside_production(): void
    {
        config()->set('services.klikqris', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://klikqris.com/api/sandbox',
            'sandbox_api_key' => 'test-only-key',
            'sandbox_merchant_id' => 'merchant-test',
            'qris_ttl_minutes' => 15,
        ]);

        $this->assertTrue(app(KlikQrisProviderReadiness::class)->sandbox()->isReady());
        $this->assertTrue(app(KlikQrisSandboxCheckoutSelector::class)->enabled());
    }

    public function test_production_environment_can_never_enable_sandbox_checkout(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.env', 'production');
        config()->set('services.klikqris.sandbox_enabled', true);
        config()->set('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        config()->set('services.klikqris.sandbox_api_key', 'test-only-key');
        config()->set('services.klikqris.sandbox_merchant_id', 'merchant-test');
        config()->set('services.klikqris.qris_ttl_minutes', 15);

        $report = app(KlikQrisProviderReadiness::class)->sandbox();

        $this->assertFalse($report->isReady());
        $this->assertContains('APP_ENV', $report->problemLabels());
        $this->assertFalse(app(KlikQrisSandboxCheckoutSelector::class)->enabled());
    }

    public function test_sandbox_reports_disabled_missing_credentials_wrong_url_and_invalid_ttl(): void
    {
        config()->set('services.klikqris', [
            'sandbox_enabled' => false,
            'sandbox_base_url' => 'http://evil.example.test/api/sandbox/',
            'sandbox_api_key' => '',
            'sandbox_merchant_id' => '   ',
            'qris_ttl_minutes' => 4,
        ]);

        $report = app(KlikQrisProviderReadiness::class)->sandbox();

        $this->assertFalse($report->isReady());
        $this->assertSame([
            'KLIKQRIS_SANDBOX_ENABLED',
            'KLIKQRIS_SANDBOX_API_KEY',
            'KLIKQRIS_SANDBOX_MERCHANT_ID',
            'KLIKQRIS_SANDBOX_BASE_URL_SCHEME',
            'KLIKQRIS_SANDBOX_BASE_URL_HOST',
            'KLIKQRIS_QRIS_TTL_MINUTES',
        ], $report->problemLabels());
        $this->assertStringContainsString('KLIKQRIS_SANDBOX_API_KEY', $report->configurationMessage());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('KlikQRIS sandbox is not ready.');

        $report->assertReady('KlikQRIS sandbox is not ready.');
    }

    public function test_sandbox_rejects_base_url_paths_other_than_api_sandbox_after_normalization(): void
    {
        config()->set('services.klikqris', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://klikqris.com/api/live/',
            'sandbox_api_key' => 'test-only-key',
            'sandbox_merchant_id' => 'merchant-test',
            'qris_ttl_minutes' => 15,
        ]);

        $report = app(KlikQrisProviderReadiness::class)->sandbox();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_SANDBOX_BASE_URL_PATH', $report->problemLabels());
    }

    public function test_provider_transaction_token_is_encrypted_at_rest(): void
    {
        $this->artisan('migrate', [
            '--path' => getcwd().'/database/migrations/2026_07_28_000001_add_provider_transaction_token_to_payments.php',
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertTrue(
            Schema::hasColumn('payments', 'provider_transaction_token'),
            'The provider_transaction_token column must come from the committed payments migration.',
        );

        $payment = Payment::factory()->create([
            'provider_transaction_token' => 'sandbox-token-123',
        ]);

        $this->assertSame('sandbox-token-123', $payment->refresh()->provider_transaction_token);

        $storedToken = DB::table('payments')->where('id', $payment->getKey())->value('provider_transaction_token');
        $this->assertIsString($storedToken);
        $this->assertNotSame('sandbox-token-123', $storedToken);
        $this->assertStringNotContainsString('sandbox-token-123', $storedToken);
    }

    public function test_native_klikqris_helpers_use_payload_qris_metadata_and_payment_deadline(): void
    {
        $payment = Payment::factory()->make([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'provider_payload' => [
                'qris_url' => '  https://klikqris.com/qr/sandbox-123  ',
            ],
            'amount' => 10000,
            'provider_total_payment' => 10025,
            'provider_expires_at' => now()->addMinutes(10),
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertTrue($payment->isNativeKlikQris());
        $this->assertTrue($payment->isPayableNativeKlikQris());
        $this->assertSame('https://klikqris.com/qr/sandbox-123', $payment->klikQrisQrisUrl());
        $this->assertSame('Rp 10.025', $payment->klikQrisTotalAmountDisplay());
    }

    public function test_native_klikqris_is_not_payable_without_pending_status_valid_amount_or_unexpired_deadline(): void
    {
        $basePayment = [
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'provider_payload' => ['qris_url' => 'https://klikqris.com/qr/sandbox-123'],
            'amount' => 10000,
            'provider_total_payment' => 9999,
            'provider_expires_at' => now()->addMinutes(10),
            'status' => PaymentStatus::Pending,
        ];

        $this->assertFalse(Payment::factory()->make($basePayment)->isPayableNativeKlikQris());
        $this->assertFalse(Payment::factory()->make(array_merge($basePayment, [
            'provider_total_payment' => 10000,
            'provider_expires_at' => now()->subMinute(),
        ]))->isPayableNativeKlikQris());
        $this->assertFalse(Payment::factory()->make(array_merge($basePayment, [
            'provider_total_payment' => 10000,
            'provider_expires_at' => now()->addMinute(),
            'status' => PaymentStatus::Paid,
        ]))->isPayableNativeKlikQris());
    }

    public function test_pakasir_native_qris_behavior_is_unchanged(): void
    {
        $payment = Payment::factory()->make([
            'provider' => 'pakasir',
            'provider_payment_method' => 'qris',
            'provider_payment_number' => '00020101021226610016COM.NOBUBANK.WWW...',
            'provider_payment_number_hash' => hash('sha256', '00020101021226610016COM.NOBUBANK.WWW...'),
            'amount' => 10000,
            'provider_total_payment' => 10025,
            'status' => PaymentStatus::Pending,
        ]);

        $this->assertTrue($payment->isNativePakasirQris());
        $this->assertTrue($payment->isPayableNativePakasirQris());
    }
}
