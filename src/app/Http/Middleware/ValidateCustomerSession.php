<?php

namespace App\Http\Middleware;

use App\Services\Customer\CustomerSessionAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');
        if ($customer !== null) {
            $version = $request->session()->get('customer_auth_version', 0);
            if ($customer->email_verified_at === null || $version !== (int) $customer->auth_version) {
                app(CustomerSessionAuthentication::class)->logout($request);
                if (in_array('auth:customer', $request->route()->gatherMiddleware(), true)) {
                    return redirect()->route('customer.account')
                        ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
                }
            }
        }

        return $next($request);
    }
}
