<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationInfrastructureTest extends TestCase
{
    public function test_health_endpoint_returns_success(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_queue_database_connection_is_configured(): void
    {
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertSame('jobs', config('queue.connections.database.table'));
    }

    public function test_supervisor_database_workers_isolate_update_and_outbound_queues(): void
    {
        $contents = file_get_contents(base_path('../supervisord.conf'));

        $this->assertStringContainsString('queue:work database', $contents);
        $this->assertStringContainsString('[program:laravel-update-worker]', $contents);
        $this->assertStringContainsString('--queue=default', $contents);
        $this->assertStringContainsString('[program:laravel-outbound-worker]', $contents);
        $this->assertStringContainsString('--queue=telegram-outbound,notifications', $contents);

        $healthCheck = file_get_contents(app_path('Console/Commands/HealthCheck.php'));
        $this->assertStringContainsString('laravel-update-worker:*', $healthCheck);
        $this->assertStringContainsString('laravel-outbound-worker:*', $healthCheck);
    }

    public function test_docker_scheduler_runs_laravel_schedule_run_loop(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.yml'));
        $dockerfile = file_get_contents(base_path('../Dockerfile'));
        $script = file_get_contents(base_path('../docker-scheduler.sh'));

        $this->assertStringContainsString('scheduler:', $compose);
        $this->assertStringContainsString('APP_AUTO_MIGRATE: "false"', $compose);
        $this->assertStringContainsString('php artisan ops:health --scheduler-heartbeat=180', $compose);
        $this->assertStringContainsString('COPY docker-scheduler.sh /usr/local/bin/docker-scheduler-laravel', $dockerfile);
        $this->assertStringContainsString('php artisan schedule:run', $script);
        $this->assertStringContainsString('storage/framework/scheduler-heartbeat', $script);
    }

    public function test_production_php_upload_limits_allow_product_images(): void
    {
        $dockerfile = file_get_contents(base_path('../Dockerfile'));

        $this->assertStringContainsString("echo 'upload_max_filesize=8M';", $dockerfile);
        $this->assertStringContainsString("echo 'post_max_size=10M';", $dockerfile);
    }

    public function test_production_deploy_workflow_uses_existing_compose_project_name(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/deploy-production.yml'));

        $this->assertStringContainsString('COMPOSE_PROJECT_NAME: telegram-bot-prod', $workflow);
        $this->assertStringContainsString('docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env up -d --no-build --remove-orphans', $workflow);
        $this->assertStringNotContainsString('docker compose --env-file .env up -d --build --remove-orphans', $workflow);
        $this->assertStringNotContainsString('docker compose -p "$COMPOSE_PROJECT_NAME" --env-file .env up -d --build --remove-orphans', $workflow);
    }

    public function test_queue_migration_contains_required_tables(): void
    {
        $migration = database_path('migrations/0001_01_01_000002_create_jobs_table.php');

        $this->assertFileExists($migration);

        $contents = file_get_contents($migration);
        $this->assertStringContainsString("Schema::create('jobs'", $contents);
        $this->assertStringContainsString("Schema::create('failed_jobs'", $contents);
        $this->assertStringContainsString("Schema::create('job_batches'", $contents);
    }

    public function test_env_example_uses_postgresql_and_database_queue(): void
    {
        $contents = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('DB_CONNECTION=pgsql', $contents);
        $this->assertStringContainsString('DB_HOST=db', $contents);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $contents);
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
}
