<?php

namespace App\Services\Customer;

use App\Enums\CustomerAuthTokenPurpose;
use App\Models\Customer;
use App\Models\CustomerAuthToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CustomerEmailOtpService
{
    public function __construct(
        private readonly CustomerAuthenticationRateLimiter $limits,
        private readonly CustomerAuthMailDeliveryService $mail,
        private readonly CustomerSessionAuthentication $sessions,
    ) {}

    public function requestCode(string $email, Request $request): ?string
    {
        $email = Str::lower(trim($email));
        if (! $this->limits->allowMail($email, (string) $request->ip())) {
            return null;
        }
        $challenge = bin2hex(random_bytes(32));
        $binding = $this->browserBinding($request);
        try {
            return DB::transaction(function () use ($email, $challenge, $binding): ?string {
                $customer = Customer::query()->where('email', $email)->lockForUpdate()->first();
                if (! $customer || $customer->email_verified_at === null) {
                    return $challenge;
                }
                $latest = CustomerAuthToken::query()->where('customer_id', $customer->id)
                    ->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->orderByDesc('id')->first();
                if ($latest && $latest->created_at->greaterThan(now()->subSeconds(60))) {
                    return null;
                }
                CustomerAuthToken::query()->where('customer_id', $customer->id)
                    ->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->whereNull('consumed_at')
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                $code = sprintf('%06d', random_int(0, 999999));
                $challengeHash = hash('sha256', $challenge);
                $createdAt = now();
                $token = new CustomerAuthToken;
                $token->forceFill([
                    'customer_id' => $customer->id,
                    'email' => $email,
                    'purpose' => CustomerAuthTokenPurpose::EmailOtp,
                    'token_hash' => self::codeDigest($email, $challengeHash, $code),
                    'challenge_hash' => $challengeHash,
                    'session_binding_hash' => hash('sha256', $binding),
                    'auth_version' => $customer->auth_version,
                    'enrollment_version' => $customer->enrollment_version,
                    'expires_at' => $createdAt->copy()->addSeconds(CustomerAuthTokenPurpose::EmailOtp->ttlSeconds()),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();
                $this->mail->persist($token, $code);

                return $challenge;
            });
        } catch (Throwable) {
            Log::warning('customer.otp_unavailable', ['operation' => 'request', 'outcome' => 'unavailable']);

            return null;
        }
    }

    public function consume(string $email, #[\SensitiveParameter] string $challenge, #[\SensitiveParameter] string $code, Request $request): bool
    {
        $email = Str::lower(trim($email));
        if (preg_match('/\A[0-9a-f]{64}\z/', $challenge) !== 1
            || ! $this->limits->allowOtpConsume((string) $request->ip())) {
            return false;
        }
        $binding = $request->session()->get('customer_otp_binding');
        if (! is_string($binding)) {
            return false;
        }
        try {
            $customer = DB::transaction(function () use ($email, $challenge, $code, $binding): ?Customer {
                $challengeHash = hash('sha256', $challenge);
                $customerId = CustomerAuthToken::query()->where('challenge_hash', $challengeHash)
                    ->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->value('customer_id');
                if ($customerId === null) {
                    return null;
                }
                // Customer-first order matches reset, resend and mail delivery locks.
                $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
                $token = CustomerAuthToken::query()->where('challenge_hash', $challengeHash)
                    ->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->lockForUpdate()->first();
                if (! $customer || ! $token || ! $token->isActiveFor(CustomerAuthTokenPurpose::EmailOtp)
                    || $customer->email_verified_at === null || $email !== $token->email || $email !== $customer->email
                    || $token->auth_version !== $customer->auth_version || $token->enrollment_version !== $customer->enrollment_version
                    || ! hash_equals((string) $token->session_binding_hash, hash('sha256', $binding))) {
                    return null;
                }
                if (preg_match('/\A[0-9]{6}\z/', $code) !== 1
                    || ! hash_equals($token->token_hash, self::codeDigest($email, $challengeHash, $code))) {
                    $token->forceFill(['attempts' => $token->attempts + 1])->save();

                    return null;
                }
                $token->forceFill(['consumed_at' => now()])->save();

                return $customer;
            });
            if (! $customer) {
                return false;
            }
            $request->session()->forget(['customer_otp_binding', 'customer_otp_challenge', 'customer_otp_email']);
            $this->sessions->authenticate($customer, $request);

            return true;
        } catch (Throwable) {
            Log::warning('customer.otp_unavailable', ['operation' => 'consume', 'outcome' => 'unavailable']);

            return false;
        }
    }

    public static function codeDigest(string $email, string $challengeHash, #[\SensitiveParameter] string $code): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Customer authentication key unavailable.');
        }

        return hash_hmac('sha256', "customer-email-otp:v1\0email_otp\0".$email."\0".$challengeHash."\0".$code, $key);
    }

    private function browserBinding(Request $request): string
    {
        $binding = $request->session()->get('customer_otp_binding');
        if (! is_string($binding)) {
            $binding = bin2hex(random_bytes(32));
            $request->session()->put('customer_otp_binding', $binding);
        }

        return $binding;
    }
}
