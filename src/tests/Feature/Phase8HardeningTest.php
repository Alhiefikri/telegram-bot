<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class Phase8HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        URL::forceScheme(null);

        parent::tearDown();
    }

    public function test_force_https_is_configurable_and_safe_by_default_for_tests(): void
    {
        $this->assertFalse((bool) config('app.force_https'));

        config([
            'app.force_https' => true,
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.tripay.api_key' => 'tripay-key',
            'services.tripay.private_key' => 'tripay-private',
            'services.tripay.merchant_code' => 'T0001',
            'services.tripay.callback_url' => 'https://example.test/api/webhook/tripay',
            'services.tripay.return_url' => 'https://example.test/thanks',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);
        app()->detectEnvironment(fn () => 'production');

        (new AppServiceProvider(app()))->boot();

        $this->assertStringStartsWith('https://', URL::to('/up'));
    }

    public function test_force_https_does_not_break_loopback_http_compose_requests(): void
    {
        config([
            'app.force_https' => true,
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.tripay.api_key' => 'tripay-key',
            'services.tripay.private_key' => 'tripay-private',
            'services.tripay.merchant_code' => 'T0001',
            'services.tripay.callback_url' => 'https://example.test/api/webhook/tripay',
            'services.tripay.return_url' => 'https://example.test/thanks',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
        ]);
        app()->detectEnvironment(fn () => 'production');
        app()->instance('request', Request::create('http://localhost:8080/admin/login'));

        (new AppServiceProvider(app()))->boot();

        $this->assertStringStartsWith('http://localhost:8080/', URL::to('/livewire/livewire.min.js'));
    }

    public function test_tripay_webhook_uses_named_rate_limiter(): void
    {
        config(['services.tripay.webhook_rate_limit_per_minute' => 1]);

        $payload = ['merchant_ref' => 'phase8-hardening-rate-limit'];

        $this->postJson('/api/webhook/tripay', $payload);

        $this->postJson('/api/webhook/tripay', $payload)->assertTooManyRequests();
    }

    public function test_logging_defaults_to_daily_rotation_and_env_example_exposes_defaults(): void
    {
        $logging = file_get_contents(config_path('logging.php'));
        $this->assertStringContainsString("env('LOG_STACK', 'daily')", $logging);

        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('LOG_STACK=daily', $env);
        $this->assertStringContainsString('LOG_DAILY_DAYS=14', $env);
    }

    public function test_failed_job_monitor_alerts_when_threshold_exceeded(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Synthetic phase 8 failure',
            'failed_at' => now(),
        ]);

        $this->artisan('ops:check-failed-jobs --max=0')
            ->expectsOutputToContain('ALERT failed_jobs count 1 exceeds max 0')
            ->assertFailed();
    }

    public function test_restore_drill_script_is_safe_and_supports_required_formats(): void
    {
        $script = base_path('../scripts/restore-db.sh');

        $this->assertFileExists($script);

        $contents = file_get_contents($script);
        $this->assertStringContainsString('--dry-run', $contents);
        $this->assertStringContainsString('RESTORE_CONFIRM', $contents);
        $this->assertStringContainsString('*.sql.gz', $contents);
        $this->assertStringContainsString('*.sql.gz.gpg', $contents);
    }

    public function test_production_smoke_script_checks_up_endpoint_for_configurable_base_url(): void
    {
        $script = base_path('../scripts/smoke-production.sh');

        $this->assertFileExists($script);

        $contents = file_get_contents($script);
        $this->assertStringContainsString('BASE_URL', $contents);
        $this->assertStringContainsString('/up', $contents);
        $this->assertStringContainsString('curl --fail', $contents);
    }

    public function test_admin_asset_smoke_script_builds_and_checks_vite_manifest(): void
    {
        $script = base_path('../scripts/smoke-admin-assets.sh');

        $this->assertFileExists($script);

        $contents = file_get_contents($script);
        $this->assertStringContainsString('npm --prefix "$APP_DIR" run build', $contents);
        $this->assertStringContainsString('public/build/manifest.json', $contents);
        $this->assertStringContainsString('/admin/login', $contents);
        $this->assertStringContainsString('resources/css/filament/admin/theme.css', $contents);
    }

    public function test_admin_asset_smoke_workflow_uses_no_production_credentials(): void
    {
        $workflow = base_path('../.github/workflows/admin-asset-smoke.yml');

        $this->assertFileExists($workflow);

        $contents = file_get_contents($workflow);
        $this->assertStringContainsString('../scripts/smoke-admin-assets.sh', $contents);
        $this->assertStringContainsString('npm ci', $contents);
        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $contents);
        $this->assertStringNotContainsString('TRIPAY_API_KEY', $contents);
    }

    public function test_docker_compose_has_vps_resource_caps_and_log_rotation(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));

        foreach (['app:', 'web:', 'db:'] as $service) {
            $this->assertStringContainsString($service, $compose);
        }

        $this->assertStringContainsString('cpus:', $compose);
        $this->assertStringContainsString('mem_limit:', $compose);
        $this->assertStringContainsString('max-size: "10m"', $compose);
    }
}
