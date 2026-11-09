<?php

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

$publicDirectory = realpath(__DIR__.'/../../public');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestedFile = $publicDirectory === false || ! is_string($requestPath)
    ? false
    : realpath($publicDirectory.$requestPath);

if (
    $requestedFile !== false
    && $publicDirectory !== false
    && str_starts_with($requestedFile, $publicDirectory.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
Facade::setFacadeApplication($app);

$app->booted(function () use ($app): void {
    config([
        'services.turnstile.site_key' => 'browser-only-turnstile-site-key',
        'services.turnstile.secret_key' => 'browser-only-turnstile-secret-key',
    ]);

    // Keep the real verifier; only its external provider is a browser fixture.
    $http = $app->make(Factory::class);
    $http->fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => fn (ClientRequest $request) => $http->response([
            'success' => $request['response'] === 'browser-checkout-proof'
                && $request['secret'] === 'browser-only-turnstile-secret-key',
            'action' => 'checkout',
            'hostname' => parse_url(config('app.url'), PHP_URL_HOST),
        ]),
    ]);
    $http->preventStrayRequests();
});

$app->handleRequest(Request::capture());
