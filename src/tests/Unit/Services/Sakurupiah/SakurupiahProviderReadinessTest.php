<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Models\ManualPaymentSetting;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahChannelAddition;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SakurupiahProviderReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ManualPaymentSetting::create(['payment_mode' => 'sakurupiah', 'is_active' => true, 'sakurupiah_channel' => 'QRISC']);
    }

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
            code: 'QRISC',
            name: 'QRISC Custom Merchant',
            minimum: 200,
            maximum: 20_000_000,
            type: 'DIRECT',
            status: 'Aktif',
            addition: new SakurupiahChannelAddition(defaultExpiredHours: 24),
        );
        $channels = [
            new SakurupiahChannel('QRISMU', 'QRISMU', 500, 5_000_000, 'DIRECT', 'Online'),
            new SakurupiahChannel('QRIS2', 'QRIS2', 100, 10_000_000, 'DIRECT', 'Online'),
            new SakurupiahChannel('QRIS', 'QRIS', 500, 2_000_000, 'DIRECT', 'Online'),
            $channel,
        ];
        $readiness = app(SakurupiahProviderReadiness::class);
        $readiness->invalidate();
        $calls = 0;
        $reader = function () use (&$calls, $channels): array {
            $calls++;

            return $channels;
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
            'QRISC',
            'QRISC Custom Merchant',
            200,
            20_000_000,
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
        $channel = new SakurupiahChannel('QRISC', 'QRISC Custom Merchant', 200, 20_000_000, 'DIRECT', 'Aktif');

        $report = app(SakurupiahProviderReadiness::class)->observe(
            SakurupiahEnvironment::Production,
            static fn (): array => [$channel],
        );

        $this->assertFalse($report->isReady());
        $this->assertTrue($report->channelObserved());
        $this->assertArrayNotHasKey('SAKURUPIAH_PRODUCTION_CALLBACK_ROUTE', $report->invalid());
        $this->assertArrayNotHasKey('SAKURUPIAH_RECONCILIATION_COMMAND', $report->invalid());
        $this->assertArrayNotHasKey('SAKURUPIAH_RECONCILIATION_SCHEDULE', $report->invalid());
        $readiness = app(SakurupiahProviderReadiness::class);
        $this->assertFalse($readiness->schedulingEnabledFor(SakurupiahEnvironment::Production));

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
                'kode' => 'QRISC',
                'nama' => 'QRISC Custom Merchant',
                'minimal' => 200,
                'maksimal' => 20_000_000,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => 24],
            ]],
        ], 200));

        $readiness = app(SakurupiahProviderReadiness::class);
        $readiness->invalidate();
        $report = $readiness->production();

        $this->assertTrue($report->channelObserved());
        $this->assertSame('QRISC', $report->qrisChannel()?->code);
        Http::assertSentCount(1);
    }

    public function test_production_supervisor_gate_accepts_the_shipped_release_worker_configuration(): void
    {
        $this->app->instance('env', 'production');
        $this->configureProduction();

        $report = app(SakurupiahProviderReadiness::class)->observe(
            SakurupiahEnvironment::Production,
            static fn (): array => [new SakurupiahChannel('QRISC', 'QRISC Custom Merchant', 200, 20_000_000, 'DIRECT', 'Aktif')],
        );

        $this->assertArrayNotHasKey('SAKURUPIAH_SUPERVISOR_QUEUE', $report->invalid());
    }

    #[DataProvider('supervisorReleaseWorkerCommands')]
    public function test_production_supervisor_gate_evaluates_the_release_worker_command(
        string $contents,
        string $queueConnection,
        bool $ready,
    ): void {
        $this->app->instance('env', 'production');
        $this->configureProduction();
        config()->set('queue.default', $queueConnection);

        $path = tempnam(sys_get_temp_dir(), 'sakurupiah-supervisor-');
        if ($path === false) {
            $this->fail('Unable to create a supervisor configuration fixture.');
        }
        file_put_contents($path, $contents);

        try {
            $report = (new SakurupiahProviderReadiness(
                SakurupiahEnvironment::Production,
                [$path],
            ))->observe(
                SakurupiahEnvironment::Production,
                static fn (): array => [new SakurupiahChannel('QRISC', 'QRISC Custom Merchant', 200, 20_000_000, 'DIRECT', 'Aktif')],
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            $ready,
            ! array_key_exists('SAKURUPIAH_SUPERVISOR_QUEUE', $report->invalid()),
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function supervisorReleaseWorkerCommands(): array
    {
        $update = "[program:laravel-update-worker]\nprocess_name=%(program_name)s_%(process_num)02d\n";
        $payments = "\n[program:laravel-payment-verification-worker]\nprocess_name=%(program_name)s_%(process_num)02d\n";

        return [
            'runtime connections inherited' => [
                $update.'command=php /var/www/html/artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=3600'
                    .$payments.'command=php /var/www/html/artisan queue:work --queue=payments --sleep=1 --tries=3 --timeout=60 --max-time=3600',
                'redis',
                true,
            ],
            'explicit runtime connections' => [
                $update.'command=php /var/www/html/artisan queue:work redis --queue=default --sleep=1'
                    .$payments.'command=php /var/www/html/artisan queue:work redis --queue=payments --sleep=1',
                'redis',
                true,
            ],
            'legacy connections matching the runtime' => [
                $update.'command=php /var/www/html/artisan queue:work database --queue=default --sleep=1'
                    .$payments.'command=php /var/www/html/artisan queue:work database --queue=payments --sleep=1',
                'database',
                true,
            ],
            'hard-coded foreign connection after a driver migration' => [
                $update.'command=php /var/www/html/artisan queue:work database --queue=default --sleep=1'
                    .$payments.'command=php /var/www/html/artisan queue:work database --queue=payments --sleep=1',
                'redis',
                false,
            ],
            'missing supervised default queue' => [
                $payments.'command=php /var/www/html/artisan queue:work --queue=payments --sleep=1',
                'redis',
                false,
            ],
            'payments still share the interaction worker' => [
                $update.'command=php /var/www/html/artisan queue:work --queue=payments,default --sleep=1',
                'redis',
                false,
            ],
            'verification queue priority reversed' => [
                $update.'command=php /var/www/html/artisan queue:work --queue=default --sleep=1'
                    .$payments.'command=php /var/www/html/artisan queue:work --queue=default,payments --sleep=1',
                'redis',
                false,
            ],
            'listener instead of a release worker' => [
                $update.'command=php /var/www/html/artisan queue:work --queue=default --sleep=1'
                    .$payments.'command=php /var/www/html/artisan queue:listen --queue=payments',
                'redis',
                false,
            ],
            'release worker program missing' => [
                "[program:laravel-outbound-worker]\ncommand=php /var/www/html/artisan queue:work --queue=telegram-outbound --sleep=1",
                'redis',
                false,
            ],
        ];
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
