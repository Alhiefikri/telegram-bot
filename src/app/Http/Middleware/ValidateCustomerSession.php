<?php

namespace App\Http\Middleware;

use App\Enums\CustomerIdentityProvider;
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
            $telegramProof = $request->session()->get('customer_telegram_authenticated') === true
                && $customer->telegram_id !== null
                && $customer->identities()->where('provider', CustomerIdentityProvider::Telegram->value)
                    ->where('provider_subject', (string) $customer->telegram_id)->exists();
            if (($customer->email_verified_at === null && ! $telegramProof) || $version !== (int) $customer->auth_version) {
                app(CustomerSessionAuthentication::class)->logout($request);
                if (in_array('auth:customer', $request->route()->gatherMiddleware(), true)) {
                    return redirect()->route('customer.account')
                        ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
                }
            }
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
