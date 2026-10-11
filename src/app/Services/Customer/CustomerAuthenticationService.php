<?php

namespace App\Services\Customer;

use App\Models\AuditLog;
use App\Enums\CustomerAuthTokenPurpose;
use App\Models\CustomerAuthToken;
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
            $candidate = CustomerLoginLink::query()->where('token_hash', $hash)->first();
            if (! $candidate || $candidate->used_at !== null || ! $candidate->expires_at->isFuture()) {
                return null;
            }

            Customer::query()->upsert([[
                'email' => $candidate->email,
                'first_name' => 'Pelanggan',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['email'], ['updated_at']);
            $customer = Customer::query()->where('email', $candidate->email)->lockForUpdate()->firstOrFail();
            $link = CustomerLoginLink::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
            if ($link->used_at !== null || ! $link->expires_at->isFuture()) {
                return null;
            }

            $link->used_at = now();
            $link->save();
            $customer->forceFill([
                'email_verified_at' => now(),
                'enrollment_version' => $customer->enrollment_version + 1,
            ])->save();
            CustomerAuthToken::query()->where('customer_id', $customer->getKey())
                ->where('purpose', CustomerAuthTokenPurpose::EmailVerification->value)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            app(CustomerSessionAuthentication::class)->authenticate($customer, $request);
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
