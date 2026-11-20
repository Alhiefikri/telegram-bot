<?php

use App\Models\Payment;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Facade;
use Tests\Support\KlikQrisSandboxScenarios;

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
        'services.sakurupiah.sandbox_api_id' => 'browser-only-sakurupiah-id',
        'services.sakurupiah.sandbox_api_key' => 'browser-only-sakurupiah-key',
        'services.sakurupiah.sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
        'services.sakurupiah.sandbox_return_url' => 'https://store.example.test/shop/orders',
        'services.sakurupiah.sandbox_callback_allowed_hosts' => ['store.example.test'],
        'services.sakurupiah.sandbox_return_allowed_hosts' => ['store.example.test'],
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
    if (getenv('STOREFRONT_RELEASE_FIXTURE') === '1' && $app->environment('testing')) {
        KlikQrisSandboxScenarios::configureReadySandbox();
        config(['services.storefront.telegram_reservation_floor' => 0]);
        $providerState = trim(file_get_contents(dirname(config('database.connections.sqlite.database')).'/provider-state'));
        if ($providerState === 'EXPIRED') {
            // Model time passing, not an authoritative provider settlement or cancellation.
            Date::setTestNow(now()->addMinutes(16));
        }
        $http->fake([
            'https://klikqris.com/api/sandbox/qris/create' => fn (ClientRequest $request) => $http->response(['data' => [
                'order_id' => $request['order_id'],
                'amount' => $request['amount'],
                'total_amount' => $request['amount'] + 16,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_release_fixture.png',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]]),
            'https://klikqris.com/api/sandbox/qris/status/*' => function (ClientRequest $request) use ($http, $providerState) {
                $reference = basename($request->url());
                $payment = Payment::query()->where('provider', 'klikqris')->where('merchant_ref', $reference)->firstOrFail();

                return $http->response(KlikQrisSandboxScenarios::statusPayload($payment, [
                    'status' => $providerState,
                    'paid_at' => $providerState === 'SUCCESS' ? now()->toISOString() : null,
                ]));
            },
        ]);
    }
    $http->preventStrayRequests();
});

$app->handleRequest(Request::capture());
