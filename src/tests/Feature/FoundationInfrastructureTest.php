<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileKlikQrisPayments;
use App\Console\Commands\ReconcileStoreBalanceTopUps;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Providers\AppServiceProvider;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationInfrastructureTest extends TestCase
{
    public function test_health_endpoint_returns_success(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_alternate_public_host_redirects_to_configured_canonical_host_with_path_and_query(): void
    {
        config()->set('app.url', 'https://lhiestore.web.id');

        $this->get('https://www.lhiestore.web.id/shop?search=ai')
            ->assertStatus(308)
            ->assertRedirect('https://lhiestore.web.id/shop?search=ai');

        $this->get('https://lhiestore.web.id/up')->assertOk();
    }

    public function test_trusted_hosts_allow_the_canonical_www_alias_before_redirecting_it(): void
    {
        $hosts = RedirectToCanonicalHost::trustedHosts(
            ['^lhiestore\\.web\\.id$'],
            'https://lhiestore.web.id',
        );

        $this->assertContains('^www\\.lhiestore\\.web\\.id$', $hosts);
    }

    public function test_ops_health_reports_database_queue_runnable_depths(): void
    {
        $now = now()->startOfSecond();
        $this->travelTo($now);
        config(['queue.default' => 'database']);

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        DB::table('jobs')->insert([
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => $now->copy()->subSeconds(180)->timestamp,
                'created_at' => $now->copy()->subSeconds(180)->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now->copy()->addMinutes(5)->timestamp,
                'created_at' => $now->copy()->subMinutes(10)->timestamp,
            ],
            [
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 1,
                'reserved_at' => $now->timestamp,
                'available_at' => $now->copy()->subMinutes(10)->timestamp,
                'created_at' => $now->copy()->subMinutes(10)->timestamp,
            ],
        ]);

        $this->artisan('ops:health --queue-depth')
            ->expectsOutput('Queue payments: depth=0 oldest_age=0s')
            ->expectsOutput('Queue default: depth=1 oldest_age=180s')
            ->expectsOutput('Queue telegram-outbound: depth=0 oldest_age=0s')
            ->expectsOutput('Queue notifications: depth=0 oldest_age=0s')
            ->expectsOutput('Queue telegram-broadcast: depth=0 oldest_age=0s')
            ->expectsOutput('Queue telegram-stock: depth=0 oldest_age=0s')
            ->assertSuccessful();
    }

    public function test_docker_scheduler_runs_laravel_schedule_run_loop(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $script = file_get_contents(base_path('../docker-scheduler.sh'));

        $this->assertStringContainsString('scheduler:', $compose);
        $this->assertStringContainsString('APP_AUTO_MIGRATE: "false"', $compose);
        $this->assertStringContainsString('php artisan ops:health --scheduler-heartbeat=180', $compose);
        $this->assertStringContainsString('php artisan ops:health --db --queue-schema --queue-depth --supervisor', $compose);
        $this->assertStringContainsString('COPY docker-scheduler.sh /usr/local/bin/docker-scheduler-laravel', $dockerfile);
        $this->assertStringContainsString('php artisan schedule:run', $script);
        $this->assertStringContainsString('storage/framework/scheduler-heartbeat', $script);
        $this->assertStringContainsString('remaining=$(( INTERVAL_SECONDS - elapsed ))', $script);
        $this->assertStringNotContainsString('sleep "$INTERVAL_SECONDS"', $script);
        $this->assertLessThan(
            strpos($script, 'if ! run_scheduler; then'),
            strpos($script, '> "$HEARTBEAT_FILE"'),
            'The scheduler heartbeat must be stamped before each schedule:run cycle.',
        );
    }

    public function test_telegram_outbound_recovery_mutex_has_a_bounded_expiration(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($candidate): bool => str_contains((string) $candidate->command, 'telegram:recover-outbound'));

        $this->assertNotNull($event);
        $this->assertSame(45, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
    }

    public function test_klikqris_reconciliation_mutex_covers_the_execution_and_provider_retry_budgets(): void
    {
        config()->set('services.klikqris.reconcile_max_execution_seconds', 45);
        config()->set('services.provider_verification.reconciliation', [
            'timeout_seconds' => 60,
            'connect_timeout_seconds' => 3,
            'max_attempts' => 3,
            'retry_delay_ms' => 250,
            'retry_max_delay_ms' => 1000,
            'lock_safety_margin_seconds' => 8,
        ]);

        $this->assertSame(4, ReconcileKlikQrisPayments::schedulerMutexMinutes());
        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('klikqris:reconcile-balance-top-ups --chunk=100 --max-top-ups=10', $schedule);
        $this->assertStringContainsString('withoutOverlapping(ReconcileStoreBalanceTopUps::schedulerMutexMinutes())', $schedule);
        $this->assertSame(4, ReconcileStoreBalanceTopUps::schedulerMutexMinutes());
    }

    public function test_production_php_upload_limits_allow_product_images(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));

        $this->assertStringContainsString("echo 'upload_max_filesize=8M';", $dockerfile);
        $this->assertStringContainsString("echo 'post_max_size=10M';", $dockerfile);
    }

    public function test_production_entrypoint_syncs_binance_checkout_image_to_public_volume(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));

        $this->assertStringContainsString('COPY src/public/binance.jpeg /opt/lhiestore-public-binance.jpeg', $dockerfile);
        $this->assertStringContainsString('cp /opt/lhiestore-public-binance.jpeg public/binance.jpeg', $entrypoint);
    }

    public function test_production_entrypoint_syncs_the_product_placeholder_to_the_public_volume(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));
        $asset = base_path('public/assets/product-placeholder.svg');

        $this->assertFileIsReadable($asset);
        $this->assertStringContainsString('COPY src/public/assets/product-placeholder.svg /opt/lhiestore-public-product-placeholder.svg', $dockerfile);
        $this->assertStringContainsString('cp /opt/lhiestore-public-product-placeholder.svg public/assets/product-placeholder.svg', $entrypoint);
        $this->assertStringStartsWith('<svg', trim((string) file_get_contents($asset)));
    }

    public function test_broadcast_rate_limit_is_pinned_in_runtime_configuration_and_documentation(): void
    {
        $documentation = file_get_contents(base_path('../README.md'));
        $appDocumentation = file_get_contents(base_path('README.md'));
        $environment = file_get_contents(base_path('../.env.example'));

        $this->assertSame(25, config('services.telegram.broadcast_rate_limit_per_second'));
        $this->assertStringNotContainsString('TELEGRAM_BROADCAST_RATE_LIMIT_PER_SECOND=', $environment);
        $this->assertStringContainsString('application configuration pada `25` pesan/detik', $appDocumentation);
        $this->assertStringNotContainsString('TELEGRAM_BROADCAST_RATE_LIMIT_PER_SECOND', $documentation);
    }

    public function test_binance_order_id_canary_configuration_is_removed_from_runtime_surfaces(): void
    {
        $this->assertNull(config('services.binance.canary_max_age_hours'));
        $this->assertStringNotContainsString(
            'BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS',
            file_get_contents(base_path('.env.example')),
        );
        $this->assertStringNotContainsString(
            'BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS',
            file_get_contents(base_path('../.env.example')),
        );
        $this->assertStringNotContainsString(
            'BINANCE_ORDER_ID_CANARY_MAX_AGE_HOURS',
            file_get_contents(base_path('../docker-compose.yml')),
        );
    }

    public function test_phpunit_blocks_unfaked_http_requests_to_real_services(): void
    {
        $testCase = file_get_contents(base_path('tests/TestCase.php'));

        $this->assertStringContainsString('use Illuminate\Support\Facades\Http;', $testCase);
        $this->assertStringContainsString('Http::preventStrayRequests();', $testCase);
    }

    public function test_testing_runtime_uses_fake_telegram_client_outside_phpunit(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $browserSpec = file_get_contents(base_path('tests/Browser/admin-dashboard-responsive.spec.js'));

        $this->assertStringContainsString("\$this->app->environment('testing') && ! \$this->isPhpUnitProcess()", $provider);
        $this->assertStringContainsString('private function isPhpUnitProcess(): bool', $provider);
        $this->assertStringContainsString('FakeTelegramBotClient::class', $provider);
        $this->assertStringContainsString("DOGFOOD_FAKE_TELEGRAM_OUTBOUND: 'true'", $browserSpec);
    }

    public function test_local_runtime_resolves_real_telegram_client(): void
    {
        config()->set('app.env', 'local');
        config()->set('services.dogfood_timing.fake_telegram_outbound', false);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame(TelegramBotClient::class, get_class($this->app->make(TelegramBotClient::class)));
    }

    public function test_telegram_http_budgets_are_pinned_in_runtime_configuration(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $environment = file_get_contents(base_path('../.env.example'));

        $this->assertSame(30.0, config('services.telegram.http_timeout_seconds'));
        $this->assertSame(10.0, config('services.telegram.http_connect_timeout_seconds'));
        $this->assertStringNotContainsString('TELEGRAM_HTTP_TIMEOUT_SECONDS', $compose);
        $this->assertStringNotContainsString('TELEGRAM_HTTP_CONNECT_TIMEOUT_SECONDS', $compose);
        $this->assertStringNotContainsString('TELEGRAM_HTTP_TIMEOUT_SECONDS=', $environment);
        $this->assertStringNotContainsString('TELEGRAM_HTTP_CONNECT_TIMEOUT_SECONDS=', $environment);
    }
}
