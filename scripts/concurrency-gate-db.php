<?php

declare(strict_types=1);

if ($argc !== 3 || ! in_array($argv[1], ['create', 'drop'], true)) {
    fwrite(STDERR, "Usage: php scripts/concurrency-gate-db.php create|drop <database-name>\n");
    exit(2);
}

[$script, $action, $database] = $argv;

if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $database) || ! str_contains(strtolower($database), 'test')) {
    fwrite(STDERR, "Refusing unsafe concurrency database name: {$database}\n");
    exit(2);
}

$host = envValue('CONCURRENCY_DB_HOST', envValue('DB_HOST', '127.0.0.1'));
$port = envValue('CONCURRENCY_DB_PORT', envValue('DB_PORT', '5432'));
$maintenanceDatabase = envValue('CONCURRENCY_DB_ADMIN_DATABASE', envValue('POSTGRES_DB', 'postgres'));
$adminUser = envValue('CONCURRENCY_DB_ADMIN_USERNAME', envValue('POSTGRES_USER', envValue('DB_USERNAME', 'postgres')));
$adminPassword = envValue('CONCURRENCY_DB_ADMIN_PASSWORD', envValue('POSTGRES_PASSWORD', envValue('DB_PASSWORD', '')));
$owner = envValue('CONCURRENCY_DB_APP_USERNAME', $adminUser);

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $maintenanceDatabase);

try {
    $pdo = new PDO($dsn, $adminUser, $adminPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    if ($action === 'create') {
        $pdo->exec(sprintf(
            'CREATE DATABASE %s OWNER %s',
            quoteIdentifier($database),
            quoteIdentifier($owner),
        ));
        fwrite(STDOUT, "Created PostgreSQL test database {$database} owned by {$owner}.\n");
        exit(0);
    }

    $statement = $pdo->prepare(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()"
    );
    $statement->execute(['database' => $database]);
    $pdo->exec(sprintf('DROP DATABASE IF EXISTS %s', quoteIdentifier($database)));
    fwrite(STDOUT, "Dropped PostgreSQL test database {$database}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to {$action} PostgreSQL test database {$database}: {$exception->getMessage()}\n");
    exit(1);
}

function envValue(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function quoteIdentifier(string $identifier): string
{
    return '"'.str_replace('"', '""', $identifier).'"';
}
