<?php

namespace App\Http\Middleware;

use App\Services\Customer\CustomerAuthAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerAuthAvailable
{
    private const METHODS = [
        'customer.register', 'customer.login', 'customer.login-code.request', 'customer.login-code.consume',
        'customer.verification.show', 'customer.verification.consume', 'customer.password-reset.request',
        'customer.password-reset.show', 'customer.password-reset.consume', 'customer.password.enroll',
        'customer.google.redirect', 'customer.google.callback', 'customer.google.link', 'customer.google.unlink',
        'customer.telegram.login', 'customer.telegram.link', 'customer.telegram.status', 'customer.telegram.consume',
        'customer.telegram.email',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->route()?->getName(), self::METHODS, true)
            && ! app(CustomerAuthAvailability::class)->enabled()) {
            if ($request->route()?->getName() === 'customer.google.callback') {
                $request->query->replace([]);
                $request->server->set('QUERY_STRING', '');
                $request->server->set('REQUEST_URI', '/auth/google/callback');
            }
            $response = $request->expectsJson()
                ? response()->json(['status' => 'unavailable'], 503)
                : redirect()->route('customer.account')->with('customer_login_error', 'Metode ini sementara tidak tersedia. Gunakan link email atau Recovery Key.');

            return $response->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }

        return $next($request);
    }
}
