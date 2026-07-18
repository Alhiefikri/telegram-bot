<?php

namespace App\Services\Customer;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLoginLink;
use App\Notifications\CustomerLoginLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class CustomerAuthenticationService
{
    public function requestLink(string $email, Request $request): void
    {
        $email = $this->normalizeEmail($email);
        $token = bin2hex(random_bytes(32));

        $link = CustomerLoginLink::create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
            'requested_ip' => $request->ip(),
        ]);

        AuditLog::create([
            'action' => 'customer.login_link_requested',
            'new_values' => ['login_link_id' => $link->getKey()],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        Notification::route('mail', $email)->notify(new CustomerLoginLinkNotification(
            route('customer.login-link.show').'#token='.$token,
        ));
    }

    public function consume(string $token, Request $request): ?Customer
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $request): ?Customer {
            $link = CustomerLoginLink::query()->where('token_hash', $hash)->lockForUpdate()->first();

            if (! $link || ! hash_equals((string) $link->token_hash, $hash) || $link->used_at !== null || $link->expires_at->isPast()) {
                return null;
            }

            $link->used_at = now();
            $link->save();

            Customer::query()->upsert([[
                'email' => $link->email,
                'first_name' => 'Pelanggan',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['email'], ['updated_at']);
            $customer = Customer::query()->where('email', $link->email)->lockForUpdate()->firstOrFail();

            $customer->forceFill(['email_verified_at' => now()])->save();
            auth('customer')->login($customer);
            $request->session()->regenerate();
            AuditLog::create([
                'action' => 'customer.login_link_consumed',
                'new_values' => ['login_link_id' => $link->getKey(), 'customer_id' => $customer->getKey()],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return $customer;
        });
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
