<?php

use App\Http\Middleware\AuthorizeAdminAccess;
use App\Http\Middleware\AuthorizeBscReceiverMutation;
use App\Http\Middleware\AuthorizeStoreBalanceCompensation;
use App\Http\Middleware\CustomerApiBoundary;
use App\Http\Middleware\PreventAdminMiniAppCache;
use App\Http\Middleware\RedirectToCanonicalHost;
use App\Http\Middleware\TrustConfiguredHosts;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\ValidateStorefrontSignedInvoice;
use App\Services\CustomerApi\CustomerApiFailure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\ValidatePathEncoding;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $middleware->use([
            ValidatePathEncoding::class,
            InvokeDeferredCallbacks::class,
            TrustConfiguredHosts::class,
            TrustConfiguredProxies::class,
            CustomerApiBoundary::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'credentials', 'token', 'code', 'challenge', 'state', 'access_key']);
        $exceptions->dontReportWhen(fn (Throwable $error) => CustomerApiBoundary::matches(request()));
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! CustomerApiBoundary::matches($request)) {
                return null;
            }
            $failure = CustomerApiFailure::fromException($error);
            $requestId = $request->attributes->get('customer_api_request_id') ?? (string) Str::uuid();

            return response()->json(['error' => ['code' => $failure->machineCode, 'message' => $failure->getMessage(),
                'request_id' => $requestId] + $failure->details], $failure->status, $failure->headers)
                ->header('X-Request-ID', $requestId)
                ->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        });
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
            fn (Request $request) => CustomerApiBoundary::matches($request) || $request->is('api/*'),
        );
    })->create();
