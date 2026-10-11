<?php

namespace App\Services\Customer;

use App\Enums\CustomerAuthTokenPurpose;
use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use App\Models\CustomerAuthToken;
use App\Models\CustomerLoginLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class CustomerPasswordAuthenticationService
{
    public function __construct(
        private readonly CustomerPasswordHasher $hasher,
        private readonly CustomerSessionAuthentication $sessions,
        private readonly CustomerAuthenticationRateLimiter $limits,
        private readonly CustomerAuthMailDeliveryService $mail,
    ) {}

    public function register(string $email, #[\SensitiveParameter] string $password, ?string $name): void
    {
        $email = $this->normalizeEmail($email);
        if (! $this->limits->allowMail($email, (string) request()->ip())) {
            return;
        }
        try {
            $proposedHash = $this->hasher->make($password);
            DB::transaction(function () use ($email, $proposedHash, $name): void {
                if (Customer::query()->where('email', $email)->lockForUpdate()->exists()) {
                    return;
                }
                // A unique email conflict rolls back this enrollment; it never updates the competing Customer.
                $customer = Customer::query()->create([
                    'email' => $email,
                    'first_name' => $name ?? 'Pelanggan',
                ]);
                $customer->forceFill(['auth_version' => 0, 'enrollment_version' => 1])->save();
                $this->issue($customer, CustomerAuthTokenPurpose::EmailVerification, $proposedHash);
            });
        } catch (Throwable) {
            $this->unavailable('registration');
        }
    }

    public function enrollTelegramEmail(string $email, Request $request): void
    {
        $customer = $request->user('customer');
        $freshAt = $request->session()->get('customer_authenticated_at');
        $email = $this->normalizeEmail($email);
        if (! $customer || $request->session()->get('customer_telegram_authenticated') !== true
            || ! is_int($freshAt) || $freshAt > now()->timestamp || $freshAt < now()->subMinutes(5)->timestamp
            || ! $this->limits->allowMail($email, (string) $request->ip())) {
            return;
        }
        try {
            DB::transaction(function () use ($customer, $email, $request): void {
                $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
                if (! $locked || $locked->email_verified_at !== null || $locked->password !== null
                    || ($locked->email !== null && $locked->email !== $email)
                    || $request->session()->get('customer_auth_version') !== $locked->auth_version
                    || ! $locked->identities()->where('provider', CustomerIdentityProvider::Telegram->value)
                        ->where('provider_subject', (string) $locked->telegram_id)->exists()
                    || Customer::query()->where('email', $email)->where('id', '<>', $locked->id)->exists()) {
                    return;
                }
                $locked->forceFill(['email' => $email, 'enrollment_version' => $locked->enrollment_version + 1])->save();
                $plainToken = bin2hex(random_bytes(32));
                $createdAt = now();
                $token = new CustomerAuthToken;
                $token->forceFill([
                    'customer_id' => $locked->id,
                    'email' => $email,
                    'purpose' => CustomerAuthTokenPurpose::EmailVerification,
                    'token_hash' => hash('sha256', $plainToken),
                    'session_binding_hash' => hash('sha256', $request->session()->getId()),
                    'auth_version' => $locked->auth_version,
                    'enrollment_version' => $locked->enrollment_version,
                    'expires_at' => $createdAt->copy()->addMinutes(30),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();
                $this->mail->persist($token, $plainToken);
            });
        } catch (Throwable) {
            $this->unavailable('telegram_email_enrollment');
        }
    }

    public function verify(#[\SensitiveParameter] string $token, Request $request): bool
    {
        if (! $this->validToken($token)) {
            return false;
        }
        try {
            return DB::transaction(function () use ($token, $request): bool {
                [$customer, $challenge] = $this->lockChallenge($token, CustomerAuthTokenPurpose::EmailVerification);
                if (! $customer || ! $challenge || $customer->email_verified_at !== null || $customer->password !== null) {
                    return false;
                }
                $telegramEnrollment = $challenge->proposed_password_hash === null
                    && is_string($challenge->session_binding_hash)
                    && hash_equals($challenge->session_binding_hash, hash('sha256', $request->session()->getId()))
                    && $customer->telegram_id !== null
                    && $customer->identities()->where('provider', CustomerIdentityProvider::Telegram->value)
                        ->where('provider_subject', (string) $customer->telegram_id)->exists();
                if (! $telegramEnrollment && (! is_string($challenge->proposed_password_hash) || $challenge->proposed_password_hash === '')) {
                    return false;
                }
                $attributes = [
                    'email_verified_at' => now(),
                    'enrollment_version' => $customer->enrollment_version + 1,
                ];
                if (! $telegramEnrollment) {
                    $attributes['password'] = $challenge->proposed_password_hash;
                    $attributes['password_scheme'] = CustomerPasswordHasher::SCHEME;
                }
                $customer->forceFill($attributes)->save();
                $this->invalidateChallenges($customer);
                $this->sessions->authenticate($customer, $request);

                return true;
            });
        } catch (Throwable) {
            $this->unavailable('verification');

            return false;
        }
    }

    public function login(string $email, #[\SensitiveParameter] string $password, Request $request): bool
    {
        $email = $this->normalizeEmail($email);
        $ip = (string) $request->ip();
        $reservation = $this->limits->reserveLogin($email, $ip);
        if ($reservation === false) {
            return false;
        }
        try {
            $success = DB::transaction(function () use ($email, $password, $request): bool {
                $customer = Customer::query()->where('email', $email)->lockForUpdate()->first();
                if (! $customer || ! is_string($customer->password) || $customer->password === '') {
                    // Use the configured work factor even when no stored password exists.
                    $this->hasher->make($password);

                    return false;
                }
                $matches = $this->hasher->check($password, $customer->password, $customer->password_scheme);
                if (! $matches || $customer->email_verified_at === null) {
                    return false;
                }
                if ($this->hasher->needsRehash($customer->password, $customer->password_scheme)) {
                    $customer->forceFill([
                        'password' => $this->hasher->make($password),
                        'password_scheme' => CustomerPasswordHasher::SCHEME,
                    ])->save();
                }
                $this->sessions->authenticate($customer, $request);

                return true;
            });
            if ($success) {
                $this->limits->releaseSuccessfulLogin($reservation);
            }

            return $success;
        } catch (Throwable) {
            $this->unavailable('password_login');

            return false;
        }
    }

    public function requestReset(string $email): void
    {
        $email = $this->normalizeEmail($email);
        if (! $this->limits->allowMail($email, (string) request()->ip())) {
            return;
        }
        try {
            DB::transaction(function () use ($email): void {
                $customer = Customer::query()->where('email', $email)->lockForUpdate()->first();
                if (! $customer || $customer->email_verified_at === null) {
                    return;
                }
                CustomerAuthToken::query()->where('customer_id', $customer->getKey())
                    ->where('purpose', CustomerAuthTokenPurpose::PasswordReset->value)->whereNull('consumed_at')
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                $this->issue($customer, CustomerAuthTokenPurpose::PasswordReset);
            });
        } catch (Throwable) {
            $this->unavailable('password_reset_request');
        }
    }

    public function reset(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, Request $request): bool
    {
        if (! $this->validToken($token)) {
            return false;
        }
        try {
            $hash = $this->hasher->make($password);
            $success = DB::transaction(function () use ($token, $hash): bool {
                [$customer, $challenge] = $this->lockChallenge($token, CustomerAuthTokenPurpose::PasswordReset);
                if (! $customer || ! $challenge || $customer->email_verified_at === null) {
                    return false;
                }
                $customer->forceFill([
                    'password' => $hash,
                    'password_scheme' => CustomerPasswordHasher::SCHEME,
                    'auth_version' => $customer->auth_version + 1,
                    'enrollment_version' => $customer->enrollment_version + 1,
                ])->save();
                $this->invalidateChallenges($customer);

                return true;
            });
            if ($success) {
                $this->sessions->logout($request);
            }

            return $success;
        } catch (Throwable) {
            $this->unavailable('password_reset');

            return false;
        }
    }

    public function setPassword(Customer $customer, #[\SensitiveParameter] string $password, Request $request): bool
    {
        $authenticated = auth('customer')->user();
        $freshAt = $request->session()->get('customer_authenticated_at');
        if (! $authenticated || $authenticated->getAuthIdentifier() !== $customer->getKey()
            || ! is_int($freshAt) || $freshAt > now()->timestamp || $freshAt < now()->subMinutes(5)->timestamp) {
            return false;
        }
        try {
            $hash = $this->hasher->make($password);

            return DB::transaction(function () use ($customer, $hash, $request): bool {
                $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();
                if (! $locked || $locked->email_verified_at === null || $locked->email === null || $locked->password !== null
                    || $request->session()->get('customer_auth_version') !== $locked->auth_version) {
                    return false;
                }
                $locked->forceFill([
                    'password' => $hash,
                    'password_scheme' => CustomerPasswordHasher::SCHEME,
                    'auth_version' => $locked->auth_version + 1,
                    'enrollment_version' => $locked->enrollment_version + 1,
                ])->save();
                $this->invalidateChallenges($locked);
                $this->sessions->authenticate($locked, $request);

                return true;
            });
        } catch (Throwable) {
            $this->unavailable('password_enrollment');

            return false;
        }
    }

    private function issue(Customer $customer, CustomerAuthTokenPurpose $purpose, ?string $proposedHash = null): void
    {
        $plainToken = bin2hex(random_bytes(32));
        $createdAt = now();
        $token = new CustomerAuthToken;
        $token->forceFill([
            'customer_id' => $customer->getKey(),
            'email' => $customer->email,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $plainToken),
            'proposed_password_hash' => $proposedHash,
            'auth_version' => $customer->auth_version,
            'enrollment_version' => $customer->enrollment_version,
            'expires_at' => $createdAt->copy()->addSeconds($purpose->ttlSeconds()),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
        $this->mail->persist($token, $plainToken);
    }

    private function lockChallenge(#[\SensitiveParameter] string $plainToken, CustomerAuthTokenPurpose $purpose): array
    {
        $digest = hash('sha256', $plainToken);
        $customerId = CustomerAuthToken::query()->where('token_hash', $digest)->where('purpose', $purpose->value)->value('customer_id');
        if ($customerId === null) {
            return [null, null];
        }
        $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
        $token = CustomerAuthToken::query()->where('token_hash', $digest)->where('purpose', $purpose->value)->lockForUpdate()->first();
        if (! $customer || ! $token || ! $token->isActiveFor($purpose)
            || ! hash_equals($token->token_hash, $digest)
            || $token->customer_id !== $customer->getKey()
            || $token->email !== $customer->email
            || $token->auth_version !== $customer->auth_version
            || $token->enrollment_version !== $customer->enrollment_version) {
            return [null, null];
        }

        return [$customer, $token];
    }

    private function invalidateChallenges(Customer $customer): void
    {
        CustomerAuthToken::query()->where(function ($query) use ($customer): void {
            $query->where('customer_id', $customer->getKey())->orWhere('email', $customer->email);
        })->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
        CustomerLoginLink::query()->where('email', $customer->email)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
    }

    private function validToken(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function unavailable(string $operation): void
    {
        Log::warning('customer.authentication_unavailable', ['operation' => $operation, 'outcome' => 'unavailable']);
    }
}
