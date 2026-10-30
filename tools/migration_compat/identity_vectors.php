<?php

declare(strict_types=1);

// Offline oracle: Composer classes only, no application bootstrap or ENV loading.
require dirname(__DIR__, 2).'/src/vendor/autoload.php';

use App\Models\User;
use App\Services\Admin\AdminBalanceReportCursor;
use App\Services\Credentials\CredentialIdentityFingerprint;
use App\Services\Credentials\CredentialIdentityNormalizer;
use App\Services\Customer\CustomerEmailOtpService;
use App\Services\Customer\CustomerPasswordHasher;
use App\Services\ProductWaitlistEmailHasher;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Hashing\ArgonHasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;

const APP_KEY_VECTOR = 'base64:cDAzLXN5bnRoZXRpYy1hcHAta2V5LW9ubHktMDAwMDA=';
const IDENTITY_KEY_VECTOR = " \tp03-synthetic-identity-key\n";

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository(['app' => ['key' => APP_KEY_VECTOR]]));
Facade::setFacadeApplication($container);

function fixtures(): array
{
    return [
        'customer-short' => ['password' => 'P03-only password!é', 'scheme' => 'sha384-v1', 'consumer' => 'customer'],
        'customer-long' => ['password' => str_repeat('long-p03-', 20), 'scheme' => 'sha384-v1', 'consumer' => 'customer'],
        'customer-nul' => ['password' => "synthetic\0suffix", 'scheme' => 'sha384-v1', 'consumer' => 'customer'],
        'raw-72' => ['password' => str_repeat('x', 72), 'scheme' => 'raw', 'consumer' => 'customer'],
        'raw-73' => ['password' => str_repeat('x', 73), 'scheme' => 'raw', 'consumer' => 'customer'],
        'raw-utf8-72' => ['password' => str_repeat('é', 36), 'scheme' => 'raw', 'consumer' => 'customer'],
        'raw-utf8-73' => ['password' => str_repeat('é', 36).'x', 'scheme' => 'raw', 'consumer' => 'customer'],
        'admin' => ['password' => 'P03 AdminCase!', 'scheme' => 'raw', 'consumer' => 'admin'],
        'admin-long' => ['password' => str_repeat('admin-p03-', 12), 'scheme' => 'raw', 'consumer' => 'admin'],
        'recovery' => ['password' => " \tlhie-p03syntheticrecovery\n", 'scheme' => 'raw', 'consumer' => 'recovery'],
    ];
}

function hashers(): array
{
    return [
        'bcrypt' => new BcryptHasher(['rounds' => 4, 'verify' => true]),
        'argon2i' => new ArgonHasher(['memory' => 1024, 'time' => 2, 'threads' => 1, 'verify' => true]),
        'argon2id' => new Argon2IdHasher(['memory' => 1024, 'time' => 2, 'threads' => 1, 'verify' => true]),
    ];
}

function recoveryInput(string $value): string
{
    $method = new ReflectionMethod(StorefrontOrderAccess::class, 'normalize');
    return $method->invoke(new StorefrontOrderAccess, $value);
}

function sourceInput(array $fixture): string
{
    if ($fixture['consumer'] === 'recovery') {
        return recoveryInput($fixture['password']);
    }
    if ($fixture['scheme'] === 'sha384-v1') {
        $method = new ReflectionMethod(CustomerPasswordHasher::class, 'input');
        return $method->invoke(new CustomerPasswordHasher(new BcryptHasher), $fixture['password']);
    }
    return $fixture['password'];
}

function verifyRecord(array $fixture, string $algorithm, string $hash): bool
{
    $hasher = hashers()[$algorithm];
    if ($fixture['consumer'] === 'customer') {
        return (new CustomerPasswordHasher($hasher))->check($fixture['password'], $hash, $fixture['scheme']);
    }
    $value = $fixture['consumer'] === 'recovery' ? recoveryInput($fixture['password']) : $fixture['password'];
    return $hasher->check($value, $hash);
}

function primitiveVectors(): array
{
    $normalizer = new CredentialIdentityNormalizer;
    $fingerprint = new CredentialIdentityFingerprint(IDENTITY_KEY_VECTOR);
    $values = [
        ['email', " \tCase.Tag+P03@Example.INVALID\n"],
        ['email', '"case"."tag"@example.invalid'],
        ['email', 'case@[IPv6:2001:db8::1]'],
        ['email', 'case@[192.0.2.1]'],
        ['auth_token', '  P03-AbC-token  '],
        ['auth_token', 'p03-abc-token'],
        ['auth_token', 'Case.Tag+P03@Example.INVALID'],
    ];
    $identities = [];
    foreach ($values as [$kind, $value]) {
        $identities[] = [$kind, $value, $normalizer->normalize($kind, $value), $fingerprint->forIdentity($kind, $value)];
    }
    $invalid = ['user@localhost', 'double..dot@example.invalid', 'é@example.invalid', 'user@example.123', 'user@-bad.invalid', str_repeat('x', 65).'@example.invalid', "\u{00a0}user@example.invalid"];
    $invalidResults = [];
    foreach ($invalid as $value) {
        try {
            $normalizer->normalizeEmail($value);
            $invalidResults[] = false;
        } catch (InvalidArgumentException) {
            $invalidResults[] = true;
        }
    }
    $waitlist = new ProductWaitlistEmailHasher;
    $email = $waitlist->normalize(" \tP03.É@Example.INVALID\n");
    $fallback = $waitlist->hash($email);
    config(['services.storefront.waitlist_hash_key' => " \tp03-waitlist-override\n"]);
    $override = $waitlist->hash($email);
    $challengeHash = hash('sha256', str_repeat('a1', 32));
    $actor = new User;
    $actor->setAttribute('id', 37);
    $filters = ['section' => 'proof/é', 'currency' => 'IDR', 'telegram_id' => null, 'state' => 'pending', 'limit' => 25];
    $scope = (new ReflectionMethod(AdminBalanceReportCursor::class, 'scope'))->invoke(new AdminBalanceReportCursor, $actor, $filters);
    $routes = new RouteCollection;
    $routes->add((new Route('GET', 'p03/{order}', fn () => null))->name('p03.vector'));
    $url = new UrlGenerator($routes, Request::create('https://candidate.invalid'));
    $url->setKeyResolver(fn () => APP_KEY_VECTOR);
    $signed = $url->signedRoute('p03.vector', ['order' => 'synthetic-order', 'label' => 'A B/é'], new DateTimeImmutable('@2000000000'), false);
    $signature = substr($signed, strrpos($signed, 'signature=') + 10);
    $unsigned = substr($signed, 0, strrpos($signed, '&signature='));
    return [
        'identities' => $identities,
        'invalid_emails' => array_map(null, $invalid, $invalidResults),
        'key_digest' => $fingerprint->keyDigest(),
        'waitlist_normalized' => $email,
        'waitlist_fallback' => $fallback,
        'waitlist_override' => $override,
        'api_secret' => hash('sha256', str_repeat('Ab', 32)),
        'api_rotate' => hash('sha256', str_repeat('Ac', 16)),
        'api_disable' => hash('sha256', 'disable:'.str_repeat('Ac', 16)),
        'challenge' => $challengeHash,
        'otp' => CustomerEmailOtpService::codeDigest('p03@example.invalid', $challengeHash, '000042'),
        'browser_nonce_binding' => hash('sha256', "p03-session\0p03-nonce"),
        'session_hash' => hash('sha256', 'p03-session'),
        'admin_cursor_scope' => $scope,
        'signed_url_unsigned' => $unsigned,
        'signed_url_digest' => $signature,
        'literal_hmac' => hash_hmac('sha256', 'p03-literal-key', APP_KEY_VECTOR),
    ];
}

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (($input['operation'] ?? null) === 'emit') {
        $records = [];
        foreach (fixtures() as $id => $fixture) {
            foreach (hashers() as $algorithm => $hasher) {
                $value = sourceInput($fixture);
                $hash = $hasher->make($algorithm === 'bcrypt' ? substr($value, 0, 72) : $value);
                $records[] = ['id' => $id, 'algorithm' => $algorithm, 'hash' => $hash];
            }
        }
        echo json_encode(['records' => $records, 'primitives' => primitiveVectors()], JSON_THROW_ON_ERROR);
    } elseif (($input['operation'] ?? null) === 'check') {
        $expectedPrimitives = primitiveVectors();
        if (($input['primitives'] ?? null) !== $expectedPrimitives) {
            throw new RuntimeException('Primitive vector mismatch.');
        }
        $fixtures = fixtures();
        $expectedIds = [];
        foreach ($fixtures as $id => $_fixture) {
            foreach (array_keys(hashers()) as $algorithm) {
                $expectedIds[] = $id.':'.$algorithm;
            }
        }
        $seen = [];
        foreach ($input['records'] ?? [] as $record) {
            $id = $record['id'];
            $algorithm = $record['algorithm'];
            if (! isset($fixtures[$id]) || ! isset(hashers()[$algorithm]) || in_array($id.':'.$algorithm, $seen, true)) {
                throw new RuntimeException('Unexpected password vector.');
            }
            $fixture = $fixtures[$id];
            $expected = ! ($fixture['consumer'] === 'customer' && $fixture['scheme'] === 'raw' && $algorithm === 'bcrypt' && strlen($fixture['password']) > 72);
            if (verifyRecord($fixture, $algorithm, $record['hash']) !== $expected) {
                throw new RuntimeException('Password consumer vector mismatch.');
            }
            $fixture['password'] = 'wrong-'.$fixture['password'];
            if (verifyRecord($fixture, $algorithm, $record['hash'])) {
                throw new RuntimeException('Wrong password was accepted.');
            }
            $seen[] = $id.':'.$algorithm;
        }
        sort($seen);
        sort($expectedIds);
        if ($seen !== $expectedIds) {
            throw new RuntimeException('Password vector coverage missing.');
        }
        echo json_encode(['password_vectors' => count($seen), 'primitive_groups' => count($expectedPrimitives)], JSON_THROW_ON_ERROR);
    } else {
        throw new RuntimeException('Unknown synthetic vector operation.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Synthetic identity oracle failed.\n");
    exit(1);
}
