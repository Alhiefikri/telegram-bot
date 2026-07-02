<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-key',
            'services.pakasir.base_url' => 'https://app.pakasir.test',
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
            'services.telegram.token' => 'telegram-token',
            'services.telegram.webhook_secret' => 'telegram-secret',
            'services.telegram.payment_mode' => 'pakasir',
            'services.pakasir.slug' => 'warung-ai',
            'services.pakasir.api_key' => 'pakasir-key',
            'services.pakasir.base_url' => 'https://app.pakasir.test',
        ]);
        app()->detectEnvironment(fn () => 'production');
        app()->instance('request', Request::create('http://localhost:8080/admin/login'));

        (new AppServiceProvider(app()))->boot();

        $this->assertStringStartsWith('http://localhost:8080/', URL::to('/build/assets/app.js'));
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
        $this->assertStringContainsString('scheduler php artisan ops:health --scheduler-heartbeat', $contents);
        $this->assertStringContainsString('SCHEDULER_HEALTH_TIMEOUT', $contents);
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

    public function test_backup_script_writes_gzip_to_safe_temp_path_with_fake_compose(): void
    {
        $workspace = $this->makeShellWorkspace('backup');
        $backupDir = $workspace.'/backups';
        $fakeCompose = $workspace.'/fake-compose';
        $argsFile = $workspace.'/compose-args';

        try {
            mkdir($backupDir, 0700);
            $this->writeExecutable($fakeCompose, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "$FAKE_COMPOSE_ARGS_FILE"
printf '%s\n' 'create table shell_smoke(id int);'
SH);

            $result = $this->runShellScript(['bash', base_path('../scripts/backup-db.sh')], [
                'BACKUP_DIR' => $backupDir,
                'COMPOSE' => $fakeCompose,
                'DB_SERVICE' => 'safe-db',
                'FAKE_COMPOSE_ARGS_FILE' => $argsFile,
                'POSTGRES_DB' => 'shell_smoke',
                'POSTGRES_PASSWORD' => 'local-password',
                'POSTGRES_USER' => 'shell_user',
            ]);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('Backup written to ', $result['stdout']);

            $backups = glob($backupDir.'/shell_smoke_*.sql.gz') ?: [];
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

    public function test_admin_asset_smoke_workflow_uses_no_production_credentials(): void
    {
        $workflow = base_path('../.github/workflows/admin-asset-smoke.yml');

        $this->assertFileExists($workflow);

        $contents = file_get_contents($workflow);
        $this->assertStringContainsString('../scripts/smoke-admin-assets.sh', $contents);
        $this->assertStringContainsString('npm ci', $contents);
        $this->assertStringNotContainsString('TELEGRAM_BOT_TOKEN', $contents);
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

    public function test_docker_image_builds_assets_during_image_build(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));

        $this->assertStringContainsString('FROM node:', $dockerfile);
        $this->assertStringContainsString('npm ci', $dockerfile);
        $this->assertStringContainsString('npm run build', $dockerfile);
        $this->assertStringContainsString('COPY --from=assets /app/public/build ./public/build', $dockerfile);
    }

    public function test_docker_startup_runs_migrations_before_supervisor_by_default(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $entrypoint = file_get_contents(base_path('../docker-entrypoint.sh'));
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $envExample = file_get_contents(base_path('../.env.example'));

        $this->assertStringContainsString('ENTRYPOINT ["/usr/local/bin/docker-entrypoint-laravel"]', $dockerfile);
        $this->assertStringContainsString('php artisan migrate --force', $entrypoint);
        $this->assertStringContainsString('exec "$@"', $entrypoint);
        $this->assertStringContainsString('APP_AUTO_MIGRATE: ${APP_AUTO_MIGRATE:-true}', $compose);
        $this->assertStringContainsString('APP_AUTO_MIGRATE: false', $compose);
        $this->assertStringContainsString('APP_AUTO_MIGRATE=true', $envExample);
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
