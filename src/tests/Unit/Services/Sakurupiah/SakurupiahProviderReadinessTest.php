<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Models\ManualPaymentSetting;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahChannelAddition;
use App\Services\Sakurupiah\SakurupiahFee;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    #[DataProvider('cachedObservationStates')]
    public function test_serialized_cache_reuses_readiness_until_the_observation_expires(bool $initiallyReady): void
    {
        config()->set('cache.stores.array.serialize', true);
        config()->set('cache.serializable_classes', false);
        Cache::purge('array');
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => $this->qualifiedQrisContract(),
        ]));
        $readiness = app(SakurupiahProviderReadiness::class);
        $calls = 0;
        $available = $initiallyReady;
        $reader = function () use (&$calls, &$available): array {
            $calls++;
            if (! $available) {
                throw new \RuntimeException('Provider metadata unavailable.');
            }

            return [new SakurupiahChannel('QRISC', 'QRISC Merchant', 500, 2_000_000, 'DIRECT', 'Aktif',
                fee: SakurupiahFee::from('0.7%'),
                percent: '0.7',
                addition: new SakurupiahChannelAddition(
                    additionalFee: SakurupiahFee::from('50'),
                    kind: 'fixture',
                    defaultExpiredHours: 24,
                    settlement: 'H+1',
                ))];
        };

        $first = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $available = ! $initiallyReady;
        $second = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertSame($initiallyReady, $first->isReady());
        $this->assertSame($initiallyReady, $second->isReady());
        $this->assertSame(1, $calls);
        if ($initiallyReady) {
            $this->assertSame(500, $second->qrisChannel()?->minimumAmount());
            $this->assertSame(2_000_000, $second->qrisChannel()?->maximumAmount());
            $this->assertSame('0.7%', $second->qrisChannel()?->fee?->submitted());
            $this->assertSame('0.7', $second->qrisChannel()?->percent);
            $this->assertSame(50, $second->qrisChannel()?->addition?->additionalFee?->fixedAmount());
            $this->assertSame(24, $second->qrisChannel()?->addition?->defaultExpiredHours());
            $this->assertSame('H+1', $second->qrisChannel()?->addition?->settlement());
        }

        $this->travel(61)->seconds();
        $recovered = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertSame(! $initiallyReady, $recovered->isReady());
        $this->assertSame(2, $calls);
    }

    public static function cachedObservationStates(): array
    {
        return ['ready' => [true], 'unavailable' => [false]];
    }

    public function test_lookup_failure_observed_near_cooldown_end_does_not_extend_checkout_rejection(): void
    {
        $this->travelTo('2026-10-28 12:00:00');
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => $this->qualifiedQrisContract(),
        ]));
        $backpressure = new SakurupiahRequestBackpressure(SakurupiahEnvironment::Sandbox);
        $backpressure->observe(new \Illuminate\Http\Client\Response(
            new Response(429),
        ));
        $reader = function () use ($backpressure): array {
            $backpressure->assertAvailable('listChannels', true);

            return [new SakurupiahChannel('QRISC', 'QRISC', 500, 2_000_000, 'DIRECT', 'Aktif',
                addition: new SakurupiahChannelAddition(defaultExpiredHours: 24))];
        };
        $readiness = app(SakurupiahProviderReadiness::class);
        $this->travel(50)->seconds();
        $this->assertFalse($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->travel(9)->seconds();
        $this->assertFalse($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->travel(1)->seconds();
        $report = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertTrue($report->isReady(), 'Checkout must recover at the merchant cooldown boundary, not a new failure-cache TTL.');
        $readiness->assertCheckoutSnapshot($report, ManualPaymentSetting::query()->first());
        $this->travel(1)->seconds();
        $this->assertTrue($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
    }

    public function test_preflight_observation_expiring_before_money_lock_is_rejected(): void
    {
        $this->configureObservation();
        $this->travelTo('2026-10-28 12:00:00');
        $readiness = app(SakurupiahProviderReadiness::class);
        $report = $readiness->observe(SakurupiahEnvironment::Sandbox, fn () => [$this->readyChannel()]);
        $this->travel(60)->seconds();
        try {
            $readiness->assertCheckoutSnapshot($report, ManualPaymentSetting::query()->first());
            $this->fail('An expired preflight must not authorize persistence.');
        } catch (CheckoutPaymentUnavailable $exception) {
            $this->assertSame('snapshot_changed', $exception->reason);
        }
    }

    public function test_temporary_lookup_failure_recovers_at_retry_boundary_not_success_cache_ttl(): void
    {
        $this->configureObservation();
        $this->travelTo('2026-10-28 12:00:00');
        $calls = 0;
        $reader = function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('temporary lookup outage');
            }

            return [$this->readyChannel()];
        };
        $readiness = app(SakurupiahProviderReadiness::class);
        $failed = $readiness->observe(SakurupiahEnvironment::Sandbox, $reader);
        $this->assertSame('lookup_temporarily_unavailable', $failed->failureReason());
        $this->travel(4)->seconds();
        $this->assertFalse($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->assertSame(1, $calls);
        $this->travel(1)->seconds();
        $this->assertTrue($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->assertSame(2, $calls);
    }

    public function test_expired_success_requires_fresh_observation_and_busy_refresh_does_not_dispatch(): void
    {
        $this->configureObservation();
        $this->travelTo('2026-10-28 12:00:00');
        $readiness = app(SakurupiahProviderReadiness::class);
        $calls = 0;
        $reader = function () use (&$calls, $readiness): array {
            $calls++;
            $competing = $readiness->observe(SakurupiahEnvironment::Sandbox, fn () => throw new \LogicException('stampede'));
            $this->assertSame('observation_refresh_busy', $competing->failureReason());

            return [$this->readyChannel()];
        };
        $this->assertTrue($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->travel(59)->seconds();
        $this->assertTrue($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->assertSame(1, $calls);
        $this->travel(1)->seconds();
        $this->assertTrue($readiness->observe(SakurupiahEnvironment::Sandbox, $reader)->isReady());
        $this->assertSame(2, $calls);
    }

    public function test_cache_outage_does_not_bypass_observation_coordination(): void
    {
        $this->configureObservation();
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        $calls = 0;
        $report = app(SakurupiahProviderReadiness::class)->observe(SakurupiahEnvironment::Sandbox, function () use (&$calls): array {
            $calls++;

            return [$this->readyChannel()];
        });
        $this->assertSame(0, $calls);
        $this->assertSame('cache_unavailable', $report->failureReason());
        $this->assertFalse($report->isReady());
    }

    #[DataProvider('unsafeChannels')]
    public function test_channel_identity_activity_and_expiry_fail_closed(string $code, string $status, int $expiry, string $reason): void
    {
        $this->configureObservation();
        $report = app(SakurupiahProviderReadiness::class)->observe(SakurupiahEnvironment::Sandbox,
            fn () => [new SakurupiahChannel($code, $code, 500, 2_000_000, 'DIRECT', $status,
                addition: new SakurupiahChannelAddition(defaultExpiredHours: $expiry))]);
        $this->assertFalse($report->isReady());
        $this->assertSame($reason, $report->failureReason());
    }

    public static function unsafeChannels(): array
    {
        return [
            'wrong identity' => ['QRISMU', 'Aktif', 12, 'channel_inactive'],
            'inactive' => ['QRISC', 'Offline', 24, 'channel_inactive'],
            'wrong expiry' => ['QRISC', 'Aktif', 12, 'channel_invalid'],
        ];
    }

    private function configureObservation(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true, 'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id', 'sandbox_api_key' => 'sandbox-key',
            'qris_contract' => $this->qualifiedQrisContract(),
        ]));
    }

    private function readyChannel(): SakurupiahChannel
    {
        return new SakurupiahChannel('QRISC', 'QRISC', 500, 2_000_000, 'DIRECT', 'Aktif',
            addition: new SakurupiahChannelAddition(defaultExpiredHours: 24));
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
