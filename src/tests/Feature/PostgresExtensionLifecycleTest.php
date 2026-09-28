<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('postgres-release')]
final class PostgresExtensionLifecycleTest extends TestCase
{
    public function test_fresh_postgres_schema_reset_recreates_extension_owned_statistics_views(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL extension lifecycle check requires the pgsql connection.');
        }

        $this->assertSame('testing', app()->environment());
        $this->assertSame('', (string) config('database.connections.pgsql.url'));
        $this->assertSame('', (string) env('DB_URL', ''));
        $database = (string) config('database.connections.pgsql.database');
        $this->assertMatchesRegularExpression('/(^|[_-])(test|testing)([_-]|$)/i', $database);
        $this->assertSame($database, (string) DB::selectOne('select current_database() as database')->database);

        $this->assertSame(1, DB::table('pg_extension')->where('extname', 'pg_stat_statements')->count());
        $this->assertNotNull(DB::scalar("select to_regclass('public.pg_stat_statements')"));

        $exitCode = PostgresTestDatabase::migrateFresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, DB::table('pg_extension')->where('extname', 'pg_stat_statements')->count());
        $this->assertNotNull(DB::scalar("select to_regclass('public.pg_stat_statements')"));
    }
}
