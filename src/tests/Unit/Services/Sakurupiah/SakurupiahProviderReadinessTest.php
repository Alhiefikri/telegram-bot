<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahChannelAddition;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SakurupiahProviderReadinessTest extends TestCase
{
    public function test_disabled_or_incomplete_environment_is_not_ready(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => false,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => null,
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertFalse($report->isReady());
        $this->assertContains('provider enabled flag', $report->missing());
        $this->assertContains('API key', $report->missing());
    }

    public function test_ready_sandbox_requires_the_provider_api_sanbox_path(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('base URL', $report->invalid());
    }

    public function test_production_configuration_is_rejected_outside_production_runtime(): void
    {
        config()->set('services.sakurupiah', [
            'production_enabled' => true,
            'production_base_url' => 'https://sakurupiah.id/api/',
            'production_api_id' => 'api-production',
            'production_api_key' => 'production-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('APP_ENV', $report->invalid());
    }

    public function test_ready_sandbox_has_a_complete_separate_credential_pair(): void
    {
        config()->set('services.sakurupiah', [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
        ]);

        $report = app(SakurupiahProviderReadiness::class)->report(SakurupiahEnvironment::Sandbox);

        $this->assertTrue($report->isReady());
    }

    public function test_observation_is_cached_by_environment_and_configuration_fingerprint(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => $this->qualifiedQrisContract(),
        ]));

        $channel = new SakurupiahChannel(
            code: 'QRIS',
            name: 'QRIS',
            minimum: 500,
            maximum: 2_000_000,
            type: 'DIRECT',
            status: 'Aktif',
            addition: new SakurupiahChannelAddition(defaultExpiredHours: 24),
        );
        $readiness = app(SakurupiahProviderReadiness::class);
        $readiness->invalidate();
        $calls = 0;
        $reader = function () use (&$calls, $channel): array {
            $calls++;

            return [$channel];
        };

        $first = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $second = $readiness->observe(
            SakurupiahEnvironment::Sandbox,
            static fn (): array => throw new \RuntimeException('cache was not used'),
        );

        $this->assertTrue($first->isReady());
        $this->assertTrue($second->isReady());
        $this->assertSame($channel, $second->qrisChannel());
        $this->assertSame(1, $calls);
        $this->assertIsString($first->configurationFingerprint());
        $this->assertStringNotContainsString('sandbox-key', $first->configurationFingerprint());

        config()->set('services.sakurupiah.sandbox_api_key', 'rotated-sandbox-key');
        $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertSame(2, $calls);

        $readiness->invalidate();
        $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertSame(3, $calls);
    }

    public function test_unqualified_qris_contract_fails_closed_after_channel_observation(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => false,
                'fee_payer' => 'customer',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => null,
                'expiry_confirmed' => false,
            ],
        ]));
        $channel = new SakurupiahChannel(
            'QRIS',
            'QRIS',
            500,
            2_000_000,
            'DIRECT',
            'Aktif',
            addition: new SakurupiahChannelAddition(defaultExpiredHours: 24),
        );

        $report = app(SakurupiahProviderReadiness::class)->observe(
            SakurupiahEnvironment::Sandbox,
            static fn (): array => [$channel],
        );

        $this->assertFalse($report->isReady());
        $this->assertTrue($report->channelObserved());
        $this->assertSame($channel, $report->qrisChannel());
        $this->assertArrayHasKey('SAKURUPIAH_QRIS_CURRENCY_CONTRACT', $report->invalid());
        $this->assertArrayHasKey('SAKURUPIAH_QRIS_FEE_CONTRACT', $report->invalid());
        $this->assertArrayHasKey('SAKURUPIAH_QRIS_EXPIRY_CONTRACT', $report->invalid());
    }

    public function test_production_observation_checks_operational_prerequisites_and_never_calls_unsafe_urls(): void
    {
        $this->app->instance('env', 'production');
        $this->configureProduction();
        $channel = new SakurupiahChannel('QRIS', 'QRIS', 500, 2_000_000, 'DIRECT', 'Aktif');

        $report = app(SakurupiahProviderReadiness::class)->observe(
            SakurupiahEnvironment::Production,
            static fn (): array => [$channel],
        );

        $this->assertFalse($report->isReady());
        $this->assertTrue($report->channelObserved());
        $this->assertArrayHasKey('SAKURUPIAH_PRODUCTION_CALLBACK_ROUTE', $report->invalid());
        $this->assertArrayHasKey('SAKURUPIAH_RECONCILIATION_COMMAND', $report->invalid());

        config()->set('services.sakurupiah.production_callback_url', 'http://store.example.test/api/webhook/sakurupiah');
        $calls = 0;
        $unsafe = app(SakurupiahProviderReadiness::class)->observe(
            SakurupiahEnvironment::Production,
            function () use (&$calls, $channel): array {
                $calls++;

                return [$channel];
            },
        );

        $this->assertFalse($unsafe->isReady());
        $this->assertSame(0, $calls);
        $this->assertArrayHasKey('SAKURUPIAH_PRODUCTION_CALLBACK_URL_SCHEME', $unsafe->invalid());
    }

    public function test_production_report_observes_channels_once_through_the_registered_adapter(): void
    {
        $this->app->instance('env', 'production');
        $this->configureProduction();
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'kode' => 'QRIS',
                'nama' => 'QRIS',
                'minimal' => 500,
                'maksimal' => 2_000_000,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => 24],
            ]],
        ], 200));

        $readiness = app(SakurupiahProviderReadiness::class);
        $readiness->invalidate();
        $report = $readiness->production();

        $this->assertTrue($report->channelObserved());
        $this->assertSame('QRIS', $report->qrisChannel()?->code);
        Http::assertSentCount(1);
    }

    private function configureProduction(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'production_enabled' => true,
            'production_base_url' => 'https://sakurupiah.id/api/',
            'production_api_id' => 'production-id',
            'production_api_key' => 'production-key',
            'production_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'production_return_url' => 'https://store.example.test/shop/orders',
            'production_allowed_hosts' => ['sakurupiah.id'],
            'production_callback_allowed_hosts' => ['store.example.test'],
            'production_return_allowed_hosts' => ['store.example.test'],
            'production_allowed_ips' => ['8.8.8.8'],
            'qris_contract' => $this->qualifiedQrisContract(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function qualifiedQrisContract(): array
    {
        return [
            'currency' => 'IDR',
            'currency_confirmed' => true,
            'fee_payer' => 'merchant',
            'merchant_fee' => 1,
            'fee_confirmed' => true,
            'expiry_hours' => 24,
            'expiry_confirmed' => true,
        ];
    }
}
