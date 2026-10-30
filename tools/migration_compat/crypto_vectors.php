<?php

declare(strict_types=1);

// Offline fixture oracle: no Laravel bootstrap, ENV loading or application I/O.
require __DIR__.'/../../src/vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

final class CryptoFixtureModel extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['text' => 'encrypted', 'document' => 'encrypted:array'];
}

function fixtureKey(bool $previous = false): string
{
    return $previous ? str_repeat('p', 32) : str_repeat('c', 32);
}

function fixtureEncrypter(bool $previous = false): Encrypter
{
    return (new Encrypter(fixtureKey($previous), 'AES-256-CBC'))
        ->previousKeys($previous ? [] : [fixtureKey(true)]);
}

function scenarios(): array
{
    $structured = [
        'version' => 1,
        'email' => 'fixture@example.invalid',
        'password' => "SYNTHETIC-only|\n日本語\0password",
        'auth_token' => str_repeat('synthetic-token:+/|', 1024),
        'notes' => ['unicode' => 'مرحبا 🧪', 'nullable' => null, 'enabled' => true],
    ];
    return [
        ['id' => 'string-unicode', 'variant' => 'string', 'expected' => "مرحبا 日本語 🧪\0line\n"],
        ['id' => 'string-empty', 'variant' => 'string', 'expected' => ''],
        ['id' => 'string-null-column', 'variant' => 'string', 'expected' => null],
        ['id' => 'string-long-legacy', 'variant' => 'string', 'expected' => 'fixture@example.invalid|'.str_repeat('SYNTHETIC|:\n', 4096)],
        ['id' => 'string-balance-cursor-json', 'variant' => 'string', 'expected' => json_encode([
            'version' => 1, 'customer_id' => 17, 'currency' => 'IDR',
            'occurred_at' => 1793318400000000, 'rank' => 1, 'id' => 23,
        ], JSON_THROW_ON_ERROR)],
        ['id' => 'json-credential', 'variant' => 'json', 'expected' => $structured],
        ['id' => 'json-list', 'variant' => 'json', 'expected' => ['日本語', null, false, 7, 1.25]],
        ['id' => 'json-empty-list', 'variant' => 'json', 'expected' => []],
        ['id' => 'json-null-column', 'variant' => 'json', 'expected' => null],
        ['id' => 'json-false', 'variant' => 'json', 'expected' => false],
        ['id' => 'json-zero', 'variant' => 'json', 'expected' => 0],
        ['id' => 'php-null', 'variant' => 'php', 'expected' => null],
        ['id' => 'php-false', 'variant' => 'php', 'expected' => false],
        ['id' => 'php-true', 'variant' => 'php', 'expected' => true],
        ['id' => 'php-int-min', 'variant' => 'php', 'expected' => PHP_INT_MIN],
        ['id' => 'php-int-max', 'variant' => 'php', 'expected' => PHP_INT_MAX],
        ['id' => 'php-float', 'variant' => 'php', 'expected' => 1.25e-20],
        ['id' => 'php-unicode', 'variant' => 'php', 'expected' => "日本語\0🧪"],
        ['id' => 'php-credential', 'variant' => 'php', 'expected' => $structured],
        ['id' => 'php-list', 'variant' => 'php', 'expected' => [null, false, 4, ['nested']]],
        ['id' => 'php-empty-array', 'variant' => 'php', 'expected' => []],
        ['id' => 'rotation-string', 'variant' => 'string', 'previous' => true, 'expected' => 'synthetic-previous-value'],
        ['id' => 'rotation-json', 'variant' => 'json', 'previous' => true, 'expected' => $structured],
        ['id' => 'rotation-php', 'variant' => 'php', 'previous' => true, 'expected' => $structured],
    ];
}

function encryptFixture(array $row): ?string
{
    $encrypter = fixtureEncrypter($row['previous'] ?? false);
    if ($row['variant'] === 'php') {
        return $encrypter->encrypt($row['expected']);
    }
    CryptoFixtureModel::encryptUsing($encrypter);
    $field = $row['variant'] === 'string' ? 'text' : 'document';
    $model = new CryptoFixtureModel;
    $model->setAttribute($field, $row['expected']);
    return $model->getAttributes()[$field];
}

function decryptFixture(array $row, ?string $envelope): mixed
{
    $encrypter = fixtureEncrypter();
    if ($row['variant'] === 'php') {
        // Only synthetic values produced by this tool or the Python codec enter here.
        // Do not use the framework's unrestricted decrypt(..., true) on imported rows.
        return unserialize($encrypter->decryptString($envelope), ['allowed_classes' => false]);
    }
    CryptoFixtureModel::encryptUsing($encrypter);
    $field = $row['variant'] === 'string' ? 'text' : 'document';
    $model = new CryptoFixtureModel;
    $model->setRawAttributes([$field => $envelope]);
    return $model->getAttribute($field);
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'generate') {
        $rows = [];
        foreach (scenarios() as $row) {
            $row['envelope'] = encryptFixture($row);
            $rows[] = $row;
        }
        // Private pipe protocol, not the public manifest.
        echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'verify') {
        $input = stream_get_contents(STDIN, 8 * 1024 * 1024 + 1);
        if (strlen($input) > 8 * 1024 * 1024) {
            throw new RuntimeException('Rejected fixture input');
        }
        $input = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
        $expected = scenarios();
        if (! is_array($input) || count($input) !== count($expected)) {
            throw new RuntimeException('Rejected fixture input');
        }
        $results = [];
        foreach ($expected as $index => $row) {
            $supplied = $input[$index];
            if ($supplied['id'] !== $row['id']) {
                throw new RuntimeException('Rejected fixture input');
            }
            $actual = decryptFixture($row, $supplied['envelope']);
            if ($actual !== $row['expected']) {
                throw new RuntimeException('Fixture mismatch');
            }
            if ($supplied['envelope'] !== null) {
                // Candidate writes must use the current key, not just any accepted key.
                (new Encrypter(fixtureKey(), 'AES-256-CBC'))->decryptString($supplied['envelope']);
            }
            $results[] = ['id' => $row['id'], 'outcome' => 'pass'];
        }
        echo json_encode($results, JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Use the Python fixture runner');
    }
} catch (Throwable $error) {
    // No raw exception, fixture value or envelope may escape into public evidence.
    fwrite(STDERR, "Synthetic crypto oracle failed\n");
    exit(1);
}
