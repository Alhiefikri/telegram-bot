<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
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
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-key',
            'services.pakasir.base_url' => 'https://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');
        app()->instance('request', Request::create('http://store.example/admin/login'));

        (new AppServiceProvider(app()))->boot();

        $this->assertStringStartsWith('https://', URL::to('/up'));
    }

    public function test_production_public_requests_generate_urls_from_app_url_not_request_host(): void
    {
        config([
            'app.force_https' => true,
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://lhiestore.example',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-key',
            'services.pakasir.base_url' => 'https://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');
        app()->instance('request', Request::create('http://43.134.177.28/shop'));

        (new AppServiceProvider(app()))->boot();

        $this->assertSame('https://lhiestore.example', URL::to('/'));
        $this->assertSame('https://lhiestore.example/shop', route('storefront.shop.index'));
    }

    public function test_force_https_does_not_break_loopback_http_compose_requests(): void
    {
        config([
            'app.force_https' => true,
            'app.env' => 'production',
            'app.key' => 'base64:test-key',
            'app.url' => 'https://example.test',
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-key',
            'services.pakasir.base_url' => 'https://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');
        app()->instance('request', Request::create('http://localhost:8080/admin/login'));

        (new AppServiceProvider(app()))->boot();

        $this->assertStringStartsWith('http://localhost:8080/', URL::to('/build/assets/app.js'));
    }

    public function test_logging_defaults_to_daily_rotation_and_env_example_exposes_only_optional_mail_backup_inputs(): void
    {
        $logging = file_get_contents(config_path('logging.php'));
        $this->assertStringContainsString("env('LOG_STACK', 'daily')", $logging);
        $this->assertStringContainsString("'permission' => 0664", $logging);

        $compose = file_get_contents(base_path('../docker-compose.yml'));
        foreach ([
            'LOG_CHANNEL: stack',
            'LOG_STACK: daily',
            'LOG_LEVEL: info',
            'LOG_DAILY_DAYS: 14',
            'LOG_DEPRECATIONS_CHANNEL: null',
        ] as $default) {
            $this->assertStringContainsString($default, $compose);
        }

        $env = file_get_contents(base_path('../.env.example'));
        $this->assertStringContainsString('MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('GPG_RECIPIENT=', $env);
        $this->assertStringContainsString('GPG_PASSPHRASE=', $env);
        $this->assertSame(90, config('services.data_retention_days'));
        $this->assertStringNotContainsString('LOG_STACK=', $env);
        $this->assertStringNotContainsString('LOG_DAILY_DAYS=', $env);
        $this->assertStringNotContainsString('DATA_RETENTION_DAYS=', $env);
        $this->assertStringNotContainsString('DOGFOOD_TIMING_LOG_CHANNEL=', $env);
    }

    public function test_dogfood_timing_uses_container_logs_and_repairs_storage_ownership_in_docker(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.benchmark.yml'));
        $productionCompose = file_get_contents(base_path('../docker-compose.yml'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));
        $perfScript = file_get_contents(base_path('scripts/perf-dogfood.php'));
        $productionBenchmark = file_get_contents(base_path('../scripts/benchmark-production.mjs'));
        $services = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString('DOGFOOD_TIMING_LOG_CHANNEL: ${DOGFOOD_TIMING_LOG_CHANNEL:-stderr}', $compose);
        $this->assertStringContainsString('APP_ALLOW_DOGFOOD_RUNTIME: "true"', $compose);
        $this->assertStringNotContainsString('DOGFOOD_TIMING_LOG_CHANNEL:', $productionCompose);
        $this->assertStringContainsString('install -d -o laravel -g laravel -m 0775 storage/logs', $entrypoint);
        $this->assertStringContainsString('chown -R laravel:laravel storage/logs', $entrypoint);
        $this->assertStringContainsString('chmod -R ug+rwX storage/logs', $entrypoint);
        $this->assertStringContainsString('chmod 2775 storage/logs', $entrypoint);
        $this->assertStringContainsString("config()->set('logging.default', 'stderr')", $perfScript);
        $this->assertStringContainsString("Log::setDefaultDriver('stderr')", $perfScript);
        $this->assertStringContainsString("config()->set('services.dogfood_timing.log_channel', 'stderr')", $perfScript);
        $this->assertStringContainsString('$fakeTelegramOutbound = (bool) config', $perfScript);
        $this->assertStringContainsString('new FakeTelegramBotClient', $perfScript);
        $this->assertStringContainsString("'run_mode' => [", $perfScript);
        $this->assertStringContainsString("'blocked_scenarios' => \$blockedScenarios", $perfScript);
        $this->assertStringContainsString("'final_queue_state' => [", $perfScript);
        $this->assertStringNotContainsString('pakasir:reconcile', $perfScript);
        $this->assertStringNotContainsString('pakasir:reconcile', $productionBenchmark);
        $this->assertStringContainsString("'log_channel' => env('DOGFOOD_TIMING_LOG_CHANNEL')", $services);
    }

    public function test_production_php_fpm_pool_handles_storefront_concurrency_budget(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));

        $this->assertStringContainsString('pm.max_children = 12', $dockerfile);
        $this->assertStringContainsString('pm.start_servers = 4', $dockerfile);
        $this->assertStringContainsString('pm.max_spare_servers = 8', $dockerfile);
    }

    public function test_nginx_routes_storefront_homepage_through_php_to_avoid_stale_assets(): void
    {
        $nginx = file_get_contents(base_path('../nginx/default.conf'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));

        $this->assertStringContainsString('resolver 127.0.0.11 valid=10s ipv6=off;', $nginx);
        $this->assertStringContainsString('set $php_upstream app:9000;', $nginx);
        $this->assertStringContainsString('location = /', $nginx);
        $this->assertStringContainsString('fastcgi_pass $php_upstream;', $nginx);
        $this->assertStringNotContainsString('fastcgi_pass app:9000;', $nginx);
        $this->assertStringContainsString('fastcgi_param REQUEST_URI /;', $nginx);
        $this->assertStringNotContainsString('try_files /storefront/index.html /index.php?$query_string;', $nginx);
        $this->assertStringContainsString('rm -f public/storefront/index.html', $entrypoint);
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

    public function test_queue_depth_monitor_alerts_when_threshold_exceeded(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'telegram-outbound',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('ops:check-queue-depth --max=0 --max-oldest-age=0 --queue=telegram-outbound')
            ->expectsOutputToContain('ALERT queue depth 1 (oldest age')
            ->assertFailed();
    }

    public function test_queue_depth_monitor_allows_fresh_live_traffic_but_rejects_stuck_work(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->artisan('ops:check-queue-depth --max=50 --max-oldest-age=120')
            ->expectsOutputToContain('OK queue depth 1')
            ->assertSuccessful();

        DB::table('jobs')->update(['created_at' => now()->subMinutes(3)->timestamp]);

        $this->artisan('ops:check-queue-depth --max=50 --max-oldest-age=120')
            ->expectsOutputToContain('exceeds thresholds max 50, max oldest age 120s')
            ->assertFailed();
    }

    public function test_telegram_webhook_check_is_secret_safe_and_fails_on_pending_updates(): void
    {
        config()->set('app.url', 'https://store.example.com');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'webhook-secret');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        Http::fake([
            'https://api.telegram.org/botbot-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://store.example.com/api/webhook/telegram',
                    'pending_update_count' => 1,
                    'allowed_updates' => ['message', 'callback_query'],
                ],
            ]),
        ]);

        $this->artisan('ops:check-telegram-webhook --max-pending=0')
            ->expectsOutputToContain('Telegram webhook telegram_ok=true')
            ->expectsOutputToContain('Telegram webhook webhook_matches_app_url=true')
            ->expectsOutputToContain('Telegram webhook pending_update_count_samples=1')
            ->doesntExpectOutputToContain('bot-token')
            ->doesntExpectOutputToContain('webhook-secret')
            ->doesntExpectOutputToContain('https://store.example.com/api/webhook/telegram')
            ->assertFailed();
    }

    public function test_telegram_webhook_observation_accepts_a_transient_pending_update_that_drains(): void
    {
        config()->set('app.url', 'https://store.example.com');
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.webhook_secret', 'webhook-secret');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        Http::fakeSequence('https://api.telegram.org/botbot-token/getWebhookInfo')
            ->push(['ok' => true, 'result' => ['url' => 'https://store.example.com/api/webhook/telegram', 'pending_update_count' => 1]])
            ->push(['ok' => true, 'result' => ['url' => 'https://store.example.com/api/webhook/telegram', 'pending_update_count' => 0]]);

        $this->artisan('ops:check-telegram-webhook --max-pending=0 --observation-seconds=1 --sample-interval=1')
            ->expectsOutputToContain('Telegram webhook pending_update_count_samples=1,0')
            ->assertSuccessful();
    }

    public function test_release_health_summary_is_machine_readable_and_secret_free(): void
    {
        $this->artisan('ops:release-health-summary')
            ->expectsOutputToContain('"candidate_release": null')
            ->assertSuccessful();
    }

    public function test_release_health_summary_separates_operational_and_unrecognized_queue_backlog(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'legacy-queue',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subDays(3)->timestamp,
        ]);

        $buffer = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->call('ops:release-health-summary', [], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"operational":', $output, $output);
        $this->assertStringContainsString('"unrecognized":', $output, $output);
        $this->assertStringContainsString('"depth": 1', $output, $output);
    }

    public function test_failed_job_release_gate_uses_id_watermark_instead_of_failure_timestamp(): void
    {
        $historicalUuid = (string) Str::uuid();
        $historicalId = DB::table('failed_jobs')->insertGetId([
            'uuid' => $historicalUuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\HistoricalJob'], JSON_THROW_ON_ERROR),
            'exception' => 'historical',
            'failed_at' => now(),
        ]);

        $this->artisan('ops:check-failed-jobs --snapshot')
            ->expectsOutput(json_encode(['max_id' => $historicalId], JSON_THROW_ON_ERROR))
            ->assertSuccessful();

        $baseline = json_encode(['max_id' => $historicalId], JSON_THROW_ON_ERROR);
        $this->artisan('ops:check-failed-jobs', ['--max' => 0, '--baseline' => $baseline])
            ->expectsOutputToContain('OK failed_jobs count 0')
            ->assertSuccessful();

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\CandidateJob'], JSON_THROW_ON_ERROR),
            'exception' => 'candidate',
            'failed_at' => now(),
        ]);

        $this->artisan('ops:check-failed-jobs', ['--max' => 0, '--baseline' => $baseline])
            ->expectsOutputToContain('ALERT failed_jobs count 1 exceeds max 0 for failures created after the pre-release baseline')
            ->assertFailed();

        $releaseScript = file_get_contents(base_path('../scripts/deploy-production-release.sh'));
        $this->assertStringContainsString('ops:check-failed-jobs --snapshot', $releaseScript);
        $this->assertStringContainsString('ops:check-failed-jobs --max=0 --baseline-stdin', $releaseScript);
        $this->assertStringNotContainsString('ops:check-failed-jobs --max=0 --since=', $releaseScript);
    }

    public function test_telegram_webhook_check_redacts_transport_failure_messages(): void
    {
        config()->set('app.url', 'https://store.example.com');
        config()->set('services.telegram.token', 'SECRET-issue-107');
        config()->set('services.telegram.webhook_secret', 'webhook-secret-issue-107');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        Http::fake([
            'https://api.telegram.org/botSECRET-issue-107/getWebhookInfo' => Http::failedConnection(
                'cURL error 6: Could not resolve host while requesting https://api.telegram.org/botSECRET-issue-107/getWebhookInfo'
            ),
        ]);

        $this->artisan('ops:check-telegram-webhook --max-pending=0')
            ->expectsOutput('Telegram webhook: request failed')
            ->doesntExpectOutputToContain('SECRET-issue-107')
            ->doesntExpectOutputToContain('https://api.telegram.org/botSECRET-issue-107/getWebhookInfo')
            ->doesntExpectOutputToContain('/botSECRET-issue-107/')
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
        $this->assertStringContainsString('--write-out', $contents);
        $this->assertStringContainsString('SMOKE_HEALTH_MARKER', $contents);
        $this->assertStringContainsString('EXPECTED_APP_URL', $contents);
        $this->assertStringContainsString('EXPECTED_RELEASE_SHA', $contents);
        $this->assertStringContainsString('TELEGRAM_MINI_APP_URL', $contents);
        $this->assertStringContainsString('$BASE_URL/admin', $contents);
        $this->assertStringContainsString('--location', $contents);
        $this->assertStringContainsString('scheduler php artisan ops:health --scheduler-heartbeat', $contents);
        $this->assertStringContainsString('SCHEDULER_HEALTH_TIMEOUT', $contents);
        $this->assertStringContainsString('SMOKE_PUBLIC_ONLY', $contents);
    }

    public function test_external_smoke_failure_triggers_locked_rollback_and_explicit_failure(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/deploy-production.yml'));
        $this->assertStringContainsString('id: external-smoke', $workflow);
        $this->assertStringContainsString("SMOKE_PUBLIC_ONLY: 'true'", $workflow);
        $this->assertStringContainsString('Roll back after external smoke failure', $workflow);
        $this->assertStringContainsString("if: steps.remote-reconcile.outcome == 'success' && steps.external-smoke.outcome == 'failure'", $workflow);
        $this->assertStringContainsString('RELEASE_OPERATION=reconcile', $workflow);
        $this->assertStringContainsString('continue-on-error: true', $workflow);
        $this->assertStringContainsString('Capture rollback release identity', $workflow);
        $this->assertStringContainsString('Re-run external public smoke after rollback', $workflow);
        $this->assertStringContainsString('ROLLBACK_SMOKE_OUTCOME', $workflow);
        $this->assertStringContainsString('RELEASE_OPERATION=rollback FAILURE_REASON=external-smoke-failed', $workflow);
        $this->assertStringContainsString('Fail release after external smoke rollback', $workflow);
        $this->assertStringContainsString('exit 20', $workflow);
        $this->assertStringContainsString('exit 21', $workflow);
    }

    public function test_restore_drill_script_dry_run_accepts_sql_gz_without_mutation(): void
    {
        $workspace = $this->makeShellWorkspace('restore');
        $script = base_path('../scripts/restore-db.sh');

        try {
            $backups = [
                $workspace.'/backup.sql' => 'select 1;',
                $workspace.'/backup.sql.gz' => gzencode('select 1;'),
                $workspace.'/backup.sql.gz.gpg' => 'encrypted backup placeholder',
            ];

            foreach ($backups as $backup => $contents) {
                file_put_contents($backup, $contents);

                $result = $this->runShellScript(['bash', $script, '--dry-run', $backup], [
                    'COMPOSE' => $workspace.'/compose-must-not-run',
                ]);

                $this->assertSame(0, $result['exitCode'], $result['stderr']);
                $this->assertStringContainsString('Restore target:', $result['stdout']);
                $this->assertStringContainsString('Dry run only; no restore executed.', $result['stdout']);
                $this->assertFileExists($backup);
            }
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_smoke_production_script_fails_closed_without_base_url(): void
    {
        $result = $this->runShellScript(['bash', base_path('../scripts/smoke-production.sh')]);

        $this->assertSame(2, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString('BASE_URL or APP_URL is required.', $result['stderr']);
        $this->assertStringNotContainsString('Smoke check passed', $result['stdout']);
    }

    public function test_smoke_production_script_fails_closed_on_bad_local_base_url(): void
    {
        $result = $this->runShellScript(['bash', base_path('../scripts/smoke-production.sh')], [
            'BASE_URL' => 'http://127.0.0.1:65536',
            'SMOKE_TIMEOUT' => '1',
        ]);

        $this->assertNotSame(0, $result['exitCode']);
        $this->assertStringNotContainsString('Smoke check passed', $result['stdout']);
    }

    public function test_smoke_production_script_fails_closed_on_bad_mini_app_dashboard_url(): void
    {
        $workspace = $this->makeShellWorkspace('smoke-mini-app');
        $fakeCurl = $workspace.'/curl';

        try {
            $this->writeExecutable($fakeCurl, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
for argument in "$@"; do
    case "$argument" in
        --output)
            shift
            output="$1"
            ;;
        http://*|https://*)
            url="$argument"
            ;;
    esac
    shift || true
done
printf '%s\n' "$url" >> "$FAKE_CURL_LOG"
case "$url" in
    */up)
        body='Application up'
        meta='200'
        ;;
    */release-sha.txt)
        body='2222222222222222222222222222222222222222'
        meta='200'
        ;;
    */telegram-mini-app)
        exit 22
        ;;
    *)
        body='LHIE STORE'
        meta='200|https://store.example/admin/login'
        ;;
esac
[ -z "$output" ] || printf '%s' "$body" > "$output"
printf '%s' "$meta"
SH);

            $curlLog = $workspace.'/curl.log';
            $result = $this->runShellScript(['bash', base_path('../scripts/smoke-production.sh')], [
                'BASE_URL' => 'https://store.example',
                'EXPECTED_APP_URL' => 'https://store.example',
                'EXPECTED_RELEASE_SHA' => '2222222222222222222222222222222222222222',
                'FAKE_CURL_LOG' => $curlLog,
                'PATH' => $workspace.':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'SMOKE_PUBLIC_ONLY' => 'true',
                'TELEGRAM_MINI_APP_URL' => 'https://store.example/telegram-mini-app',
            ]);

            $this->assertNotSame(0, $result['exitCode']);
            $this->assertStringContainsString('Smoke health check passed: https://store.example/up', $result['stdout']);
            $this->assertStringContainsString('Release identity check passed', $result['stdout']);
            $this->assertStringNotContainsString('Admin smoke check passed', $result['stdout']);
            $this->assertStringContainsString(
                "https://store.example/up\nhttps://store.example/release-sha.txt\nhttps://store.example/telegram-mini-app\n",
                file_get_contents($curlLog),
            );
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_public_only_smoke_skips_runner_local_compose_checks(): void
    {
        $workspace = $this->makeShellWorkspace('smoke-public-only');

        try {
            $this->writeExecutable($workspace.'/curl', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
for argument in "$@"; do
    case "$argument" in
        --output)
            shift
            output="$1"
            ;;
        http://*|https://*)
            url="$argument"
            ;;
    esac
    shift || true
done
case "$url" in
    */up)
        body='Application up'
        meta='200'
        ;;
    */release-sha.txt)
        body='2222222222222222222222222222222222222222'
        meta='200'
        ;;
    *)
        body='LHIE STORE'
        meta='200|https://store.example/admin/login'
        ;;
esac
[ -z "$output" ] || printf '%s' "$body" > "$output"
printf '%s' "$meta"
SH);
            $this->writeExecutable($workspace.'/docker', <<<'SH'
#!/usr/bin/env bash
echo 'docker must not run during public-only smoke' >&2
exit 99
SH);

            $result = $this->runShellScript(['bash', base_path('../scripts/smoke-production.sh')], [
                'BASE_URL' => 'https://store.example',
                'EXPECTED_APP_URL' => 'https://store.example',
                'EXPECTED_RELEASE_SHA' => '2222222222222222222222222222222222222222',
                'COMPOSE_PROJECT_NAME' => 'telegram-bot-prod',
                'PATH' => $workspace.':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'SMOKE_PUBLIC_ONLY' => 'true',
            ]);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringNotContainsString('docker must not run', $result['stderr']);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_backup_script_fails_closed_without_encryption(): void
    {
        $workspace = $this->makeShellWorkspace('backup-missing-encryption');
        $backupDir = $workspace.'/backups';
        $fakeCompose = $workspace.'/fake-compose';

        try {
            mkdir($backupDir, 0700);
            $this->writeExecutable($fakeCompose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf 'compose must not run\n' >&2
exit 99
SH);

            $result = $this->runShellScript(['bash', base_path('../scripts/backup-db.sh')], [
                'BACKUP_DIR' => $backupDir,
                'COMPOSE' => $fakeCompose,
            ]);

            $this->assertSame(2, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('Backup encryption is required', $result['stderr']);
            $this->assertStringNotContainsString('compose must not run', $result['stderr']);
            $this->assertSame([], glob($backupDir.'/*') ?: []);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_backup_script_writes_encrypted_gzip_with_fake_compose_and_gpg(): void
    {
        $workspace = $this->makeShellWorkspace('backup');
        $backupDir = $workspace.'/backups';
        $fakeCompose = $workspace.'/docker-compose';
        $fakeGpg = $workspace.'/gpg';
        $argsFile = $workspace.'/compose-args';

        try {
            mkdir($backupDir, 0700);
            $safeConfig = $workspace.'/safe-compose-config.sh';
            $this->writeExecutable($safeConfig, <<<'SH'
#!/usr/bin/env bash
exit 0
SH);

            $this->writeExecutable($fakeCompose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "$FAKE_COMPOSE_ARGS_FILE"
printf '%s\n' 'create table shell_smoke(id int);'
SH);
            $this->writeExecutable($fakeGpg, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
mode=encrypt
out=''
input_file=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --list-packets)
      exit 0
      ;;
    --decrypt)
      mode=decrypt
      ;;
    --output)
      shift
      out="$1"
      ;;
    -*)
      ;;
    *)
      input_file="$1"
      ;;
  esac
  shift || true
done
if [ "$mode" = decrypt ]; then
  cat "$input_file"
else
  cat > "$out"
fi
SH);

            $result = $this->runShellScript(['bash', base_path('../scripts/backup-db.sh')], [
                'BACKUP_DIR' => $backupDir,
                'COMPOSE' => $fakeCompose,
                'COMPOSE_CONFIG_SCRIPT' => $safeConfig,
                'DB_SERVICE' => 'safe-db',
                'FAKE_COMPOSE_ARGS_FILE' => $argsFile,
                'GPG_RECIPIENT' => 'backup@example.test',
                'PATH' => $workspace.':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
                'POSTGRES_DB' => 'shell_smoke',
                'POSTGRES_PASSWORD' => 'local-password',
                'POSTGRES_USER' => 'shell_user',
            ]);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('Backup written to ', $result['stdout']);

            $backups = glob($backupDir.'/shell_smoke_*.sql.gz.gpg') ?: [];
            $this->assertCount(1, $backups);
            $this->assertSame("create table shell_smoke(id int);\n", gzdecode((string) file_get_contents($backups[0])));
            $this->assertSame('600', substr(sprintf('%o', fileperms($backups[0]) & 0777), -3));
            $this->assertSame(
                'exec -T -e PGPASSWORD=local-password safe-db pg_dump -U shell_user -d shell_smoke --no-owner --no-privileges',
                trim((string) file_get_contents($argsFile))
            );
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_admin_asset_smoke_script_builds_and_checks_vite_manifest(): void
    {
        $script = base_path('../scripts/smoke-admin-assets.sh');

        $this->assertFileExists($script);

        $contents = file_get_contents($script);
        $this->assertStringContainsString('npm --prefix "$APP_DIR" run build', $contents);
        $this->assertStringContainsString('public/build/manifest.json', $contents);
        $this->assertStringContainsString('resources/css/app.css', $contents);
        $this->assertStringContainsString('resources/js/app.js', $contents);
        $this->assertStringNotContainsString('resources/css/filament/admin/theme.css', $contents);
    }

    public function test_ci_asset_smoke_uses_no_production_credentials(): void
    {
        $workflow = base_path('../.github/workflows/ci.yml');

        $this->assertFileExists($workflow);

        $contents = file_get_contents($workflow);
        $this->assertStringContainsString('name: Build and verify admin assets', $contents);
        $this->assertStringContainsString('./scripts/smoke-admin-assets.sh', $contents);
        $this->assertStringContainsString('npm ci', $contents);
        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $contents);
    }

    public function test_production_deploy_workflow_gates_release_readiness_image_provenance_and_post_deploy_safeguards(): void
    {
        $workflow = base_path('../.github/workflows/deploy-production.yml');

        $this->assertFileExists($workflow);

        $contents = file_get_contents($workflow);
        $release = file_get_contents(base_path('../scripts/deploy-production-release.sh'));
        $this->assertStringContainsString('DEPLOY_BRANCH: main', $contents);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME: telegram-bot-prod', $contents);
        $this->assertStringContainsString('DEPLOY_SHA: ${{ inputs.commit || github.sha }}', $contents);
        $this->assertStringContainsString('apply_telegram_outbound_contract:', $contents);
        $this->assertStringContainsString('inputs.apply_telegram_outbound_contract == true', $contents);
        $this->assertStringContainsString('bootstrap_local_rollback_image:', $contents);
        $this->assertStringContainsString('BOOTSTRAP_LOCAL_ROLLBACK_IMAGE: ${{ inputs.bootstrap_local_rollback_image }}', $contents);
        $this->assertStringContainsString('is_content_addressed_local_image', $release);
        $this->assertStringContainsString('BOOTSTRAP_LOCAL_ROLLBACK_IMAGE:-false', $release);
        $this->assertStringContainsString('initialize_credential_fingerprint_key:', $contents);
        $this->assertStringContainsString('expected_app_url:', $contents);
        $this->assertStringContainsString('EXPECTED_APP_URL: ${{ inputs.expected_app_url || vars.PRODUCTION_APP_URL }}', $contents);
        $this->assertStringContainsString('INITIALIZE_CREDENTIAL_FINGERPRINT_KEY: ${{ inputs.initialize_credential_fingerprint_key }}', $contents);
        $this->assertStringContainsString('initialize_credential_identity_fingerprint_key', $release);
        $this->assertStringContainsString('docker image inspect "$PREVIOUS_IMAGE"', $release);
        $this->assertStringContainsString('actions/workflows/ci.yml/runs?head_sha=${TARGET_SHA}', $contents);
        $this->assertStringContainsString('scripts/deploy-production-release.sh', $contents);
        $this->assertStringContainsString('git checkout --detach "$DEPLOY_SHA"', $release);
        $this->assertStringContainsString('SAFE_COMPOSE_CONFIG', $release);
        $this->assertStringContainsString('commits/${TARGET_SHA}/statuses', $contents);
        $this->assertStringContainsString('.context == "production-image"', $contents);
        $this->assertStringContainsString('ref: ${{ env.DEPLOY_SHA }}', $contents);
        $this->assertStringContainsString('CI_RUN_URL', $contents);
        $this->assertStringContainsString('.target_url == $target_url', $contents);
        $this->assertStringContainsString('EXPECTED_RELEASE_SHA', $contents);
        $this->assertStringContainsString('contract-backup-verified.txt', $release);
        $this->assertStringContainsString('release-state.env', $release);
        $this->assertStringContainsString('release-journal.log', $release);
        $this->assertStringContainsString('GHCR_TOKEN: ${{ github.token }}', $contents);
        $this->assertStringContainsString("docker login ghcr.io --username '\$GHCR_USERNAME' --password-stdin", $contents);
        $this->assertStringContainsString('docker logout ghcr.io', $contents);
        $this->assertStringNotContainsString('docker login ghcr.io', $release);
        $this->assertStringContainsString('APP_IMAGE: ${{ needs.release-gate.outputs.image_reference }}', $contents);
        $this->assertStringContainsString('docker pull "$APP_IMAGE"', $release);
        $this->assertStringNotContainsString('docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env build', $contents);
        $this->assertStringContainsString('compose up -d --no-build --remove-orphans', $release);
        $this->assertStringContainsString('running_image="$(docker inspect "$container_id" --format \'{{.Image}}\')"', $release);
        $this->assertStringNotContainsString('up -d --build --remove-orphans', $contents);
        $this->assertStringContainsString('php artisan ops:check-failed-jobs --snapshot', $release);
        $this->assertStringContainsString('php artisan ops:check-failed-jobs --max=0 --baseline-stdin', $release);
        $this->assertStringContainsString('php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5', $release);
        $this->assertMatchesRegularExpression(
            '/actions\/upload-artifact@[0-9a-f]{40} # v[0-9]+(?:\.[0-9]+)*/',
            $contents,
            'The production workflow must pin upload-artifact to an immutable SHA and document its version.',
        );
        $this->assertStringContainsString('ops:release-health-summary --baseline-stdin', $release);
        $this->assertStringContainsString('./scripts/smoke-production.sh', $contents);
        $this->assertStringContainsString('PRODUCTION_SMOKE_BASE_URL', $contents);
        $this->assertStringContainsString('group: production-mutation', $contents);
        $this->assertStringContainsString('exec 9>"$PRODUCTION_PATH/.production-mutation.lock"', $release);
        $this->assertStringContainsString('flock -n 9', $release);
        $this->assertStringContainsString('-o BatchMode=yes -o ConnectTimeout=30 -o ServerAliveInterval=30 -o ServerAliveCountMax=6', $contents);
        foreach ([
            'app health check is non-interactive' => 'compose exec -T app php artisan ops:health --db --queue-schema --queue-depth --supervisor </dev/null',
            'scheduler health check is non-interactive' => 'compose exec -T scheduler php artisan ops:health --scheduler-heartbeat=180 </dev/null',
            'failed jobs check consumes the pre-release baseline non-interactively' => 'compose exec -T app php artisan ops:check-failed-jobs --max=0 --baseline-stdin < "$FAILED_JOBS_BASELINE_FILE"',
            'Telegram update recovery is non-interactive' => 'compose exec -T app php artisan telegram:recover-failed-updates --prune-unrecoverable </dev/null',
            'Telegram webhook check is non-interactive' => 'compose exec -T app php artisan ops:check-telegram-webhook --max-pending=25 --observation-seconds=30 --sample-interval=5 </dev/null',
        ] as $contract => $command) {
            $this->assertStringContainsString($command, $release, $contract);
        }

        $this->assertStringContainsString('if ! compose exec -T app php artisan ops:check-failed-jobs', $release);
        $this->assertStringContainsString('if ! compose exec -T app php artisan ops:check-queue-depth', $release);
        $this->assertStringContainsString('--queue=payments', $release);
        $this->assertStringContainsString('--queue=default', $release);
        $this->assertStringContainsString('--queue=telegram-outbound', $release);
        $this->assertStringContainsString('--queue=notifications', $release);
        $this->assertStringContainsString('--queue=telegram-broadcast', $release);
        $this->assertStringContainsString('--queue=binance-verification', $release);
        $this->assertStringContainsString('if ! compose exec -T app php artisan ops:check-telegram-webhook', $release);

        $this->assertStringContainsString('PREVIOUS_COMMIT="$(git rev-parse HEAD)"', $release);
        $this->assertStringContainsString('PREVIOUS_IMAGE="$(docker inspect', $release);
        $this->assertStringContainsString('compose run --rm --no-deps -e APP_AUTO_MIGRATE=false', $release);
        $this->assertStringContainsString('2026_08_18_000001_contract_telegram_outbound_effects.php', $release);
        $this->assertStringContainsString('run_expand_migrations', $release);
        $this->assertStringContainsString('run_deferred_telegram_contract', $release);
        $this->assertStringContainsString('telegram:recover-outbound --chunk=100 --queue=telegram-outbound', $release);
        $this->assertStringContainsString('telegram:outbound-contract --strict', $release);
        $this->assertStringContainsString('php artisan queue:restart', $release);
        $this->assertStringContainsString('Apply deferred Telegram outbound contract', $contents);
        $this->assertStringContainsString('RELEASE_OPERATION=contract', $contents);
        $this->assertStringContainsString('deploy-succeeded-compatible-contract-pending', $release);
        $this->assertStringContainsString('contract-failed-candidate-remains', $release);
        $this->assertStringContainsString('${failure_reason}-rollback-succeeded', $release);
        $this->assertStringContainsString('${failure_reason}-rollback-failed', $release);
        $this->assertStringContainsString('duplicate_projects="$(', $release);
    }

    public function test_github_workflows_pin_actions_bound_jobs_and_enforce_dependency_audits(): void
    {
        $workflowDirectory = base_path('../.github/workflows');
        $workflowPaths = glob($workflowDirectory.'/*.yml');

        $this->assertNotEmpty($workflowPaths);

        foreach ($workflowPaths as $workflowPath) {
            $contents = file_get_contents($workflowPath);
            preg_match_all('/^\s*(?:-\s*)?uses:\s*([^\s#]+)/m', $contents, $uses);

            foreach ($uses[1] as $action) {
                if (str_starts_with($action, './') || str_starts_with($action, 'docker://')) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/@[0-9a-f]{40}$/',
                    $action,
                    basename($workflowPath).' contains an action that is not pinned to a full commit SHA: '.$action,
                );
            }

            $jobCount = preg_match_all('/^    runs-on:\s*.+$/m', $contents);
            $timeoutCount = preg_match_all('/^    timeout-minutes:\s*\d+\s*$/m', $contents);
            $this->assertSame($jobCount, $timeoutCount, basename($workflowPath).' must bound every job with a timeout.');
        }

        $ci = file_get_contents($workflowDirectory.'/ci.yml');
        $this->assertStringContainsString('actionlint', $ci);
        $this->assertStringContainsString('composer audit --locked --no-dev --abandoned=fail', $ci);
        $this->assertStringContainsString('npm audit --audit-level=high', $ci);
        $this->assertStringContainsString('application-test-failure-', $ci);
        $this->assertStringContainsString('docker-failure-', $ci);
        $this->assertSame(1, substr_count($ci, 'uses: shivammathur/setup-php@'));
        $this->assertSame(1, substr_count($ci, 'uses: actions/setup-node@'));
        $this->assertSame(1, substr_count($ci, 'run: npm ci'));
        $this->assertStringContainsString('Run customer browser smoke without rebuilding assets', $ci);
        $this->assertStringNotContainsString('cache: npm', $ci);
        $this->assertStringNotContainsString('type=gha', $ci);
        $this->assertStringContainsString('name: telegram-bot-production', $ci);
        $this->assertStringContainsString('keep-state: true', $ci);
        $this->assertStringContainsString('cache-binary: false', $ci);
        $this->assertStringContainsString('buildkitd-config-inline:', $ci);
        $this->assertStringContainsString('nameservers = ["1.1.1.1", "8.8.8.8"]', $ci);
        $this->assertStringContainsString('name: Ensure self-hosted CI base images', $ci);
        $this->assertStringContainsString('ghcr.io/alhiefikri/telegram-bot-ci-base:ubuntu-24.04', $ci);
        $this->assertStringContainsString('credentials:', $ci);
        $this->assertStringContainsString('needs: [ci-base-images, workflow-security]', $ci);
        $this->assertStringContainsString('needs: application-tests', $ci);
        $this->assertStringContainsString('for push_attempt in 1 2 3', $ci);
        $this->assertStringContainsString('GHCR push failed after 3 bounded attempts.', $ci);
        $this->assertStringContainsString("permissions:\n  contents: read\n  packages: read", $ci);
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $this->assertStringContainsString('FROM ghcr.io/alhiefikri/telegram-bot-ci-base:composer-2 AS vendor', $dockerfile);
        $this->assertStringContainsString('FROM ghcr.io/alhiefikri/telegram-bot-ci-base:node-24-slim AS assets', $dockerfile);
        $this->assertStringContainsString('FROM ghcr.io/alhiefikri/telegram-bot-ci-base:php-8.4-fpm AS runtime', $dockerfile);

        $dependabot = file_get_contents(base_path('../.github/dependabot.yml'));
        foreach (['github-actions', 'composer', 'npm'] as $ecosystem) {
            $this->assertStringContainsString('package-ecosystem: '.$ecosystem, $dependabot);
        }
    }

    public function test_ci_has_authoritative_postgresql_migration_integration_and_concurrency_release_gate(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/ci.yml'));
        $postgresConfig = file_get_contents(base_path('phpunit.postgres.xml'));
        $concurrencyGate = file_get_contents(base_path('../scripts/concurrency-deploy-gate.sh'));

        $this->assertStringContainsString('name: Application, browser, and PostgreSQL tests', $workflow);
        $this->assertStringContainsString('image: ghcr.io/alhiefikri/telegram-bot-ci-base:postgres-16-alpine', $workflow);
        $this->assertStringContainsString('--health-cmd "pg_isready -U telegram_bot_ci -d telegram_bot_ci_test"', $workflow);
        $this->assertStringContainsString('DB_CONNECTION: pgsql', $workflow);
        $this->assertStringContainsString('POSTGRES_PASSWORD: ci-only-not-production', $workflow);
        $this->assertStringContainsString('php artisan migrate:fresh --force --ansi', $workflow);
        $this->assertStringContainsString('--configuration=phpunit.postgres.xml', $workflow);
        $this->assertStringContainsString('--group=postgres-release', $workflow);
        $this->assertStringContainsString('--fail-on-skipped', $workflow);
        $this->assertStringContainsString('RUN_CONCURRENCY_TESTS: \'1\'', $workflow);
        $this->assertStringContainsString('./scripts/concurrency-deploy-gate.sh', $workflow);
        $this->assertStringContainsString('postgres-release-gate-${{ github.run_attempt }}', $workflow);
        $this->assertStringContainsString('if: always()', $workflow);

        foreach ([
            'tests/Feature/Telegram/WebhookIngressTest.php',
            'tests/Feature/ProductInventoryRaceTest.php',
            'tests/Feature/StorefrontProductCheckoutTest.php',
            'tests/Feature/Telegram/CartCheckoutTest.php',
            'tests/Feature/Telegram/PaymentProofAdminCallbackTest.php',
            'tests/Feature/AdminOrderPaymentOperationsTest.php',
        ] as $criticalTest) {
            $this->assertStringContainsString($criticalTest, $postgresConfig);
        }

        $this->assertStringContainsString('--fail-on-skipped', $concurrencyGate);
        $this->assertStringContainsString('trap cleanup EXIT', $concurrencyGate);
        $this->assertStringContainsString('junit-gate-report.php', $concurrencyGate);
    }

    public function test_failed_job_recovery_workflow_is_targeted_locked_and_verifies_the_queue(): void
    {
        $workflow = base_path('../.github/workflows/recover-failed-jobs.yml');

        $this->assertFileExists($workflow);

        $contents = file_get_contents($workflow);
        $this->assertStringContainsString('workflow_dispatch:', $contents);
        $this->assertStringContainsString('RECOVER_TELEGRAM_UPDATES', $contents);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME: telegram-bot-prod', $contents);
        $this->assertStringContainsString('group: production-mutation', $contents);
        $this->assertStringContainsString('exec 9>"$PRODUCTION_PATH/.production-mutation.lock"', $contents);
        $this->assertStringContainsString('flock -n 9', $contents);
        $this->assertStringContainsString('php artisan telegram:recover-failed-updates --dry-run', $contents);
        $this->assertStringContainsString('php artisan telegram:recover-failed-updates --prune-unrecoverable', $contents);
        $this->assertStringContainsString('php artisan ops:check-failed-jobs --max=0', $contents);
        $this->assertStringContainsString('php artisan ops:check-queue-depth --max=0', $contents);
        $this->assertStringContainsString('-o BatchMode=yes -o ConnectTimeout=30 -o ServerAliveInterval=30 -o ServerAliveCountMax=6', $contents);
        $this->assertStringNotContainsString('queue:retry all', $contents);
        $this->assertStringNotContainsString('queue:failed', $contents);
        $this->assertStringNotContainsString('queue:flush', $contents);

        $deployWorkflow = file_get_contents(base_path('../.github/workflows/deploy-production.yml'));
        preg_match('/^concurrency:\s+group: ([^\s]+)/m', $deployWorkflow, $deployConcurrency);
        preg_match('/^concurrency:\s+group: ([^\s]+)/m', $contents, $recoveryConcurrency);
        $this->assertSame('production-mutation', $deployConcurrency[1] ?? null);
        $this->assertSame($deployConcurrency[1] ?? null, $recoveryConcurrency[1] ?? null);
    }

    public function test_docker_compose_has_vps_resource_caps_and_log_rotation(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));

        foreach (['app:', 'web:', 'scheduler:', 'db:'] as $service) {
            $this->assertStringContainsString($service, $compose);
        }

        $this->assertStringContainsString('cpus:', $compose);
        $this->assertStringContainsString('mem_limit:', $compose);
        $this->assertStringContainsString('max-size: "10m"', $compose);
    }

    public function test_docker_compose_forwards_explicit_non_production_runtime_opt_in(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));

        $this->assertStringContainsString(
            'APP_ALLOW_NON_PRODUCTION_DOCKER: ${APP_ALLOW_NON_PRODUCTION_DOCKER:-false}',
            $compose,
        );
    }

    public function test_docker_compose_forwards_binance_credentials_without_transport_knobs(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $envExample = file_get_contents(base_path('../.env.example'));

        foreach ([
            'BINANCE_API_KEY',
            'BINANCE_API_SECRET',
            'BINANCE_PAY_ID',
            'BINANCE_PAY_UID',
        ] as $name) {
            $this->assertStringContainsString($name.': ${'.$name, $compose);
            $this->assertStringContainsString($name.'=', $envExample);
        }

        foreach ([
            'BINANCE_API_BASE_URL',
            'BINANCE_HISTORY_CACHE_SECONDS',
            'BINANCE_HISTORY_LOCK_SECONDS',
            'BINANCE_VERIFY_CLOCK_SKEW_SECONDS',
        ] as $name) {
            $this->assertStringNotContainsString($name, $compose);
            $this->assertStringNotContainsString($name.'=', $envExample);
        }
    }

    public function test_docker_image_builds_assets_during_image_build(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));

        $this->assertStringContainsString(' AS assets', $dockerfile);
        $this->assertStringContainsString('npm ci', $dockerfile);
        $this->assertStringContainsString('npm run build', $dockerfile);
        $this->assertStringContainsString('COPY --from=assets /app/public/build ./public/build', $dockerfile);
    }

    public function test_docker_startup_does_not_own_production_migrations(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $envExample = file_get_contents(base_path('../.env.example'));

        $this->assertStringContainsString('ENTRYPOINT ["/usr/local/bin/docker-entrypoint-laravel"]', $dockerfile);
        $this->assertStringNotContainsString('php artisan optimize', $dockerfile);
        $this->assertStringContainsString('artisan migrate --force', $entrypoint);
        $this->assertStringContainsString('artisan credentials:backfill-identity-registry --chunk=100', $entrypoint);
        $this->assertStringContainsString('artisan optimize', $entrypoint);
        $this->assertStringContainsString('exec "$@"', $entrypoint);
        $this->assertLessThan(
            strpos($entrypoint, 'artisan optimize'),
            strpos($entrypoint, 'artisan migrate --force')
        );
        $this->assertLessThan(
            strpos($entrypoint, 'exec "$@"'),
            strpos($entrypoint, 'artisan optimize')
        );
        $this->assertStringContainsString('APP_AUTO_MIGRATE: "false"', $compose);
        $this->assertStringContainsString('CREDENTIAL_IDENTITY_FINGERPRINT_KEY: ${CREDENTIAL_IDENTITY_FINGERPRINT_KEY:-}', $compose);
        $this->assertStringNotContainsString('CREDENTIAL_IDENTITY_FINGERPRINT_KEY is required', $compose);
        $this->assertStringContainsString('APP_OPTIMIZE: "true"', $compose);
        $this->assertStringNotContainsString('APP_AUTO_MIGRATE=', $envExample);
        $this->assertStringNotContainsString('APP_OPTIMIZE=', $envExample);
    }

    public function test_ops_health_checks_scheduler_heartbeat(): void
    {
        $heartbeat = storage_path('framework/scheduler-heartbeat');

        @unlink($heartbeat);

        $this->artisan('ops:health', ['--scheduler-heartbeat' => 180])
            ->expectsOutputToContain('Scheduler heartbeat: missing')
            ->assertFailed();

        file_put_contents($heartbeat, now()->toISOString());
        touch($heartbeat, time());

        $this->artisan('ops:health', ['--scheduler-heartbeat' => 180])
            ->expectsOutputToContain('Scheduler heartbeat: ok')
            ->assertSuccessful();

        touch($heartbeat, time() - 181);

        $this->artisan('ops:health', ['--scheduler-heartbeat' => 180])
            ->expectsOutputToContain('Scheduler heartbeat: stale')
            ->assertFailed();

        @unlink($heartbeat);
    }

    private function makeShellWorkspace(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/telegram-bot-'.$prefix.'-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->fail('Unable to create shell test workspace.');
        }

        return $directory;
    }

    private function writeExecutable(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
        chmod($path, 0700);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runShellScript(array $command, array $environment = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, base_path('..'), array_merge([
            'APP_URL' => '',
            'BASE_URL' => '',
            'COMPOSE_PROJECT_NAME' => '',
            'HOME' => sys_get_temp_dir(),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'SMOKE_COMPOSE_PROJECT' => '',
        ], $environment));

        if (! is_resource($process)) {
            $this->fail('Unable to start shell script process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }
}
