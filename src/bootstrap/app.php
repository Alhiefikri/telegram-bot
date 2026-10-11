<?php

use App\Http\Middleware\AuthorizeAdminAccess;
use App\Http\Middleware\AuthorizeBscReceiverMutation;
use App\Http\Middleware\AuthorizeStoreBalanceCompensation;
use App\Http\Middleware\PreventAdminMiniAppCache;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\ValidateStorefrontSignedInvoice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('customer.account'));
        $middleware->alias([
            'admin.access' => AuthorizeAdminAccess::class,
            'admin.bsc-owner' => AuthorizeBscReceiverMutation::class,
            'admin.store-balance-owner' => AuthorizeStoreBalanceCompensation::class,
            'admin.no-cache' => PreventAdminMiniAppCache::class,
            'storefront.signed' => ValidateStorefrontSignedInvoice::class,
        ]);
        $middleware->append(RedirectToCanonicalHost::class);

        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_PREFIX
            );
        }

        $trustedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_HOSTS', ''))
        )));
        if ($trustedHosts !== []) {
            $middleware->trustHosts(at: fn () => RedirectToCanonicalHost::trustedHosts(
                $trustedHosts,
                (string) config('app.url'),
            ));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'credentials', 'token', 'code', 'challenge']);
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if ($exception->getStatusCode() !== 429 || ! $request->is('akun', 'akun/*') || $request->expectsJson()) {
                return null;
            }

            return response()->view('customer.rate-limited', [
                'retryAfter' => max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60)),
            ], 429, $exception->getHeaders())
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
