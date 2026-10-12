<?php

use App\Services\Customer\GoogleOAuthIdentity;
use App\Services\Customer\GoogleOAuthProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$public = realpath(__DIR__.'/../../../public');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->booted(function () use ($app): void {
    $database = (string) config('database.connections.sqlite.database');
    if (! app()->environment('testing') || config('database.default') !== 'sqlite'
        || ! str_starts_with($database, sys_get_temp_dir().'/customer-account-browser-')) {
        throw new RuntimeException('Auth browser router requires disposable SQLite.');
    }
    Http::preventStrayRequests();
    if (is_file(dirname($database).'/providers-configured')) {
        config(['services.telegram.token' => 'disposable-browser-bot']);
        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response([
                'ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'disposable_auth_bot'],
            ]),
        ]);
        $app->bind(GoogleOAuthProvider::class, fn () => new class extends GoogleOAuthProvider
        {
            public function configurationFingerprint(): ?string
            {
                return hash('sha256', 'disposable-browser-google');
            }

            public function authorizationUrl(#[SensitiveParameter] string $state): string
            {
                return route('customer.google.callback', ['state' => $state, 'code' => 'disposable-proof']);
            }

            public function user(#[SensitiveParameter] string $code): GoogleOAuthIdentity
            {
                if ($code !== 'disposable-proof') {
                    throw new UnexpectedValueException('Invalid disposable proof.');
                }

                return new GoogleOAuthIdentity('disposable-browser-subject', 'google-browser@example.test');
            }
        });
    }
});
$app->handleRequest(Request::capture());
