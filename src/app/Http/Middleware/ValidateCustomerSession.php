<?php

namespace App\Http\Middleware;

use App\Enums\CustomerIdentityProvider;
use App\Services\Customer\CustomerSessionAuthentication;
use App\Services\Customer\CustomerTelegramDevices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');
        $devices = app(CustomerTelegramDevices::class);
        if ($customer === null && $request->isMethodSafe() && $request->is('akun', 'akun/*')) {
            $devices->restore($request);
            $customer = $request->user('customer');
        }
        if ($customer !== null) {
            $device = $request->session()->get(CustomerTelegramDevices::SESSION_KEY);
            $version = $request->session()->get('customer_auth_version', 0);
            $telegramProof = $request->session()->get('customer_telegram_authenticated') === true
                && $customer->telegram_id !== null
                && $customer->identities()->where('provider', CustomerIdentityProvider::Telegram->value)
                    ->where('provider_subject', (string) $customer->telegram_id)->exists();
            $googleIdentityId = $request->session()->get('customer_google_identity_id');
            $googleProof = is_int($googleIdentityId) && $googleIdentityId > 0
                && $customer->identities()->whereKey($googleIdentityId)
                    ->where('provider', CustomerIdentityProvider::Google->value)->exists();
            if (($customer->email_verified_at === null && ! $telegramProof && ! $googleProof) || $version !== (int) $customer->auth_version
                || (is_string($device) && (! $telegramProof || ! $devices->validFor($customer, $device)))) {
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
