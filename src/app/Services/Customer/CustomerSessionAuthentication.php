<?php

namespace App\Services\Customer;

use App\Models\Customer;
use Illuminate\Http\Request;

final class CustomerSessionAuthentication
{
    public function authenticate(Customer $customer, Request $request): void
    {
        $request->session()->forget(['customer_telegram_authenticated', 'customer_google_identity_id']);
        auth('customer')->login($customer);
        $request->session()->regenerate(true);
        $request->session()->put('customer_auth_version', $customer->auth_version);
        $request->session()->put('customer_authenticated_at', now()->timestamp);
    }

    public function logout(Request $request): void
    {
        auth('customer')->logout();
        $request->session()->forget(['customer_auth_version', 'customer_authenticated_at', 'customer_telegram_authenticated', 'customer_google_identity_id']);
        $request->session()->regenerate(true);
    }
}
