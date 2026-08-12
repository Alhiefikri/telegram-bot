<?php

namespace Tests\Feature;

use App\Enums\KlikQrisEnvironment;
use App\Services\KlikQris\KlikQrisCheckoutSelector;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\TestCase;

class KlikQrisProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_readiness_rejects_sandbox_endpoint_and_reused_sandbox_credentials(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('services.klikqris.production_base_url', 'https://klikqris.com/api/sandbox');
        config()->set('services.klikqris.production_allowed_hosts', ['klikqris.com']);
        config()->set('services.klikqris.production_api_key', config('services.klikqris.sandbox_api_key'));
        config()->set('services.klikqris.production_merchant_id', config('services.klikqris.sandbox_merchant_id'));

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_PRODUCTION_BASE_URL_SANDBOX', $report->problemLabels());
        $this->assertContains('KLIKQRIS_PRODUCTION_API_KEY_SANDBOX', $report->problemLabels());
        $this->assertContains('KLIKQRIS_PRODUCTION_MERCHANT_ID_SANDBOX', $report->problemLabels());
    }

    public function test_production_readiness_requires_a_separate_callback_host_allowlist(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('services.klikqris.production_callback_allowed_hosts', []);

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_PRODUCTION_CALLBACK_ALLOWED_HOSTS', $report->problemLabels());
    }

    public function test_production_readiness_rejects_non_production_runtime(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        $this->app['env'] = 'testing';
        config()->set('app.env', 'testing');

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('APP_ENV', $report->problemLabels());
        $this->assertFalse(app(KlikQrisCheckoutSelector::class)->enabled(KlikQrisEnvironment::Production));
    }

    public function test_production_readiness_requires_the_production_callback_route(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        app('router')->setRoutes(new RouteCollection);

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_PRODUCTION_CALLBACK_ROUTE', $report->problemLabels());
    }

    public function test_production_readiness_requires_an_asynchronous_queue(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('queue.default', 'sync');

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('QUEUE_CONNECTION', $report->problemLabels());
    }

    public function test_production_readiness_requires_queue_and_payment_schema(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        Schema::dropIfExists('jobs');

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('QUEUE_JOBS_TABLE', $report->problemLabels());
    }

    public function test_production_readiness_requires_reconciliation_schedule_and_safe_timeouts(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('services.klikqris.reconciliation_command', null);
        config()->set('services.klikqris.http_timeout_seconds', 0);

        $report = app(KlikQrisProviderReadiness::class)->production();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_RECONCILIATION_SCHEDULE', $report->problemLabels());
        $this->assertContains('KLIKQRIS_HTTP_TIMEOUT_SECONDS', $report->problemLabels());
    }

    public function test_sandbox_readiness_rejects_credentials_reused_from_production(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        config()->set('services.klikqris.production_api_key', 'production-only-key');
        config()->set('services.klikqris.production_merchant_id', 'production-only-merchant');
        config()->set('services.klikqris.sandbox_api_key', 'production-only-key');
        config()->set('services.klikqris.sandbox_merchant_id', 'production-only-merchant');

        $report = app(KlikQrisProviderReadiness::class)->sandbox();

        $this->assertFalse($report->isReady());
        $this->assertContains('KLIKQRIS_SANDBOX_API_KEY_PRODUCTION', $report->problemLabels());
        $this->assertContains('KLIKQRIS_SANDBOX_MERCHANT_ID_PRODUCTION', $report->problemLabels());
    }

    public function test_production_readiness_command_is_secret_free_and_does_not_create_transactions(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('services.klikqris.production_api_key', 'production-secret-key');
        config()->set('services.klikqris.production_merchant_id', 'production-secret-merchant');
        Http::fake();

        $exitCode = Artisan::call('klikqris:validate-production');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('PASS KlikQRIS production readiness', $output);
        $this->assertStringContainsString('SUMMARY environment=production readiness=ready', $output);
        $this->assertStringNotContainsString('production-secret-key', $output);
        $this->assertStringNotContainsString('production-secret-merchant', $output);
        Http::assertNothingSent();
    }

    public function test_production_readiness_command_reports_missing_configuration_without_provider_traffic(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();
        config()->set('services.klikqris.production_api_key', null);
        Http::fake();

        $exitCode = Artisan::call('klikqris:validate-production');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('FAIL KLIKQRIS_PRODUCTION_API_KEY configured - Value is blank or disabled.', $output);
        $this->assertStringContainsString('SUMMARY environment=production readiness=not_ready', $output);
        Http::assertNothingSent();
    }

    public function test_checkout_selector_requires_the_explicit_runtime_environment(): void
    {
        KlikQrisSandboxScenarios::configureReadyProduction();

        $selector = app(KlikQrisCheckoutSelector::class);

        $this->assertTrue($selector->enabled(KlikQrisEnvironment::Production));
        $this->assertFalse($selector->enabled(KlikQrisEnvironment::Sandbox));
        $this->assertTrue($selector->active());
    }
}
