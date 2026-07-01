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

    public function test_supervisor_database_worker_processes_notification_queue(): void
    {
        $contents = file_get_contents(base_path('../supervisord.conf'));

        $this->assertStringContainsString('queue:work database', $contents);
        $this->assertStringContainsString('--queue=notifications,default', $contents);
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
}
