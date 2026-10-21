<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Real transaction boundaries without rolling back irreversible migrations. */
abstract class NonTransactionalDatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame(0, PostgresTestDatabase::migrateFresh());
            RefreshDatabaseState::$migrated = false;

            return;
        }

        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite'
            || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Nontransactional SQLite tests require an isolated in-memory testing database.');
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }
}
