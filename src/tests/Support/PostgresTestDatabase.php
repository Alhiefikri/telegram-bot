<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresTestDatabase
{
    public static function migrateFresh(): int
    {
        if (app()->environment() !== 'testing' || DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Fresh PostgreSQL test migrations require APP_ENV=testing and the pgsql connection.');
        }

        $connection = config('database.default');
        if ((string) config("database.connections.{$connection}.url") !== '' || (string) env('DB_URL', '') !== '') {
            throw new RuntimeException('Refusing PostgreSQL test reset while DB_URL is configured.');
        }

        $host = (string) config("database.connections.{$connection}.host");
        $database = (string) config("database.connections.{$connection}.database");
        $actualDatabase = (string) DB::selectOne('select current_database() as database')->database;
        if (preg_match('/(^|[_-])(prod|production|live|main)([_-]|$)/i', $database) === 1
            || preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $database) !== 1
            || $actualDatabase !== $database) {
            throw new RuntimeException("Refusing PostgreSQL test reset for database [{$actualDatabase}] on host [{$host}].");
        }

        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');

        return Artisan::call('migrate:fresh', [
            '--force' => true,
            '--drop-views' => true,
            '--drop-types' => true,
        ]);
    }
}
