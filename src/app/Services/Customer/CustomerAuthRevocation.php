<?php

namespace App\Services\Customer;

use App\Models\Customer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CustomerAuthRevocation
{
    public function revoke(int $customerId): bool
    {
        return DB::transaction(function () use ($customerId): bool {
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first(['id', 'email', 'auth_version', 'enrollment_version']);
            if (! $customer) {
                return false;
            }
            $now = now();
            $customer->forceFill([
                'auth_version' => $customer->auth_version + 1,
                'enrollment_version' => $customer->enrollment_version + 1,
            ])->save();

            // Customer-first locking serializes issuance/consumption. SQL updates do not load grants or mail payloads.
            DB::table('customer_auth_tokens')->where('customer_id', $customerId)->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);
            if ($customer->email !== null) {
                DB::table('customer_auth_tokens')->where('email', $customer->email)->whereNull('consumed_at')
                    ->update(['consumed_at' => $now, 'updated_at' => $now]);
                DB::table('customer_login_links')->where('email', $customer->email)->whereNull('used_at')
                    ->update(['used_at' => $now, 'updated_at' => $now]);
            }
            foreach (['customer_id', 'proof_customer_id'] as $binding) {
                DB::table('customer_telegram_auth_claims')->where($binding, $customerId)
                    ->whereNull('consumed_at')->whereNull('failure')->update(['failure' => 'invalid', 'updated_at' => $now]);
            }
            // Expired Google states are already invalid and cannot legally be consumed after expiry.
            DB::table('customer_google_auth_claims')->where('customer_id', $customerId)
                ->whereNull('consumed_at')->where('expires_at', '>', $now)->update(['consumed_at' => $now, 'updated_at' => $now]);

            DB::table('customer_auth_mail_works')->where('status', 'pending')
                ->whereIn('auth_token_id', function (Builder $tokens) use ($customerId): void {
                    $tokens->select('id')->from('customer_auth_tokens')->where('customer_id', $customerId);
                })->update(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null, 'updated_at' => $now]);
            if ($customer->email !== null) {
                DB::table('customer_auth_mail_works')->where('status', 'pending')
                    ->whereIn('auth_token_id', function (Builder $tokens) use ($customer): void {
                        $tokens->select('id')->from('customer_auth_tokens')->where('email', $customer->email);
                    })->update(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null, 'updated_at' => $now]);
                DB::table('customer_auth_mail_works')->where('status', 'pending')
                    ->whereIn('login_link_id', function (Builder $links) use ($customer): void {
                        $links->select('id')->from('customer_login_links')->where('email', $customer->email);
                    })->update(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null, 'updated_at' => $now]);
            }

            return true;
        });
    }
}
