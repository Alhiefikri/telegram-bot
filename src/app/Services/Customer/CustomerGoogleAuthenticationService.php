<?php

namespace App\Services\Customer;

use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use App\Models\CustomerGoogleAuthClaim;
use App\Models\CustomerIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

final class CustomerGoogleAuthenticationService
{
    public function __construct(
        private GoogleOAuthProvider $provider,
        private CustomerAuthenticationRateLimiter $limiter,
        private CustomerSessionAuthentication $sessions,
    ) {}

    public function ready(): bool
    {
        return $this->provider->ready();
    }

    public function start(Request $request, string $operation): ?string
    {
        $request->session()->forget(['customer_google_claim_nonce', 'customer_google_claim_error']);
        try {
            if (! $this->ready() || ! $this->limiter->allowGoogleStart($request->session()->getId(), (string) $request->ip())) {
                $request->session()->put('customer_google_claim_error', 'unavailable');

                return null;
            }
            $authenticated = auth('customer')->user();
            if (! in_array($operation, ['login', 'register', 'link'], true)
                || ($operation === 'link' ? (! $authenticated || ! $this->fresh($request)) : $authenticated !== null)) {
                $request->session()->put('customer_google_claim_error', 'invalid');

                return null;
            }
            $state = $this->secret();
            $nonce = $this->secret();
            $fingerprint = $this->provider->configurationFingerprint();
            $claim = DB::transaction(function () use ($request, $operation, $authenticated, $state, $nonce, $fingerprint): ?CustomerGoogleAuthClaim {
                $customer = $operation === 'link' ? Customer::query()->whereKey($authenticated->getAuthIdentifier())->lockForUpdate()->first() : null;
                if ($operation === 'link' && (! $customer || ! $this->fresh($request)
                    || $request->session()->get('customer_auth_version') !== $customer->auth_version)) {
                    return null;
                }
                $createdAt = now();
                $claim = new CustomerGoogleAuthClaim;
                $claim->forceFill([
                    'operation' => $operation,
                    'customer_id' => $customer?->getKey(),
                    'auth_version' => $customer?->auth_version,
                    'authenticated_at' => $customer ? $createdAt->copy()->setTimestamp($request->session()->get('customer_authenticated_at')) : null,
                    'state_hash' => hash('sha256', $state),
                    'session_binding_hash' => $this->binding($request, $nonce),
                    'configuration_fingerprint' => $fingerprint,
                    'expires_at' => $createdAt->copy()->addMinutes(5),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();

                return $claim;
            });
            if (! $claim) {
                $request->session()->put('customer_google_claim_error', 'invalid');

                return null;
            }
            $request->session()->put('customer_google_claim_nonce', $nonce);

            return $this->provider->authorizationUrl($state);
        } catch (Throwable) {
            $request->session()->forget('customer_google_claim_nonce');
            $request->session()->put('customer_google_claim_error', 'unavailable');

            return null;
        }
    }

    public function callback(Request $request): string
    {
        try {
            $state = $request->query('state');
            if (! is_string($state) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $state) !== 1) {
                return 'invalid';
            }
            // Commit the one-use transition before any provider request, including a failed exchange.
            $claim = $this->consumeState($request, $state);
            if (! $claim) {
                return 'invalid';
            }
            $request->session()->forget(['customer_google_claim_nonce', 'customer_google_claim_error']);
            if (! $this->configurationMatches($claim)
                || ! $this->limiter->allowGoogleCallback($request->session()->getId(), (string) $request->ip())) {
                return 'unavailable';
            }
            $sessionId = $request->session()->getId();
            $code = $request->query('code');
            if ($request->query->has('error') || ! is_string($code) || $code === '' || strlen($code) > 4096
                || preg_match('/\A[\x21-\x7e]+\z/', $code) !== 1) {
                return 'invalid';
            }
            $identity = $this->provider->user($code);
            if (! $this->configurationMatches($claim)) {
                return 'unavailable';
            }
            if ($request->session()->getId() !== $sessionId) {
                return 'invalid';
            }

            return $claim->operation === 'link' ? $this->link($claim, $identity, $request) : $this->signIn($claim, $identity, $request);
        } catch (UnexpectedValueException) {
            return 'invalid';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    public function unlink(Request $request): string
    {
        try {
            if (! $this->ready() || ! $this->limiter->allowGoogleUnlink($request->session()->getId(), (string) $request->ip())) {
                return 'unavailable';
            }
            $authenticated = auth('customer')->user();
            if (! $authenticated || ! $this->fresh($request)) {
                return 'invalid';
            }

            $result = DB::transaction(function () use ($request, $authenticated): Customer|string {
                $customer = Customer::query()->whereKey($authenticated->getAuthIdentifier())->lockForUpdate()->first();
                if (! $customer || ! $this->fresh($request) || $request->session()->get('customer_auth_version') !== $customer->auth_version) {
                    return 'invalid';
                }
                $identity = $this->googleIdentities()->where('customer_id', $customer->getKey())->lockForUpdate()->first();
                if (! $identity) {
                    return 'invalid';
                }
                if (! $this->hasAlternative($customer)) {
                    return 'last_method';
                }
                $identity->delete();

                return $customer;
            });
            if (is_string($result)) {
                return $result;
            }
            $this->rotate($result, $request);
            $request->session()->forget('customer_google_identity_id');
            if ($result->email_verified_at === null) {
                $request->session()->put('customer_telegram_authenticated', true);
            }

            return 'unlinked';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function consumeState(Request $request, #[\SensitiveParameter] string $state): ?CustomerGoogleAuthClaim
    {
        $snapshot = CustomerGoogleAuthClaim::query()->where('state_hash', hash('sha256', $state))->first();
        if (! $snapshot) {
            return null;
        }

        return DB::transaction(function () use ($snapshot, $request): ?CustomerGoogleAuthClaim {
            $customer = $snapshot->customer_id !== null ? Customer::query()->whereKey($snapshot->customer_id)->lockForUpdate()->first() : null;
            $claim = CustomerGoogleAuthClaim::query()->whereKey($snapshot->getKey())->lockForUpdate()->first();
            $nonce = $request->session()->get('customer_google_claim_nonce');
            if (! $claim || $claim->consumed_at !== null || $claim->expires_at->lte(now())
                || ! is_string($nonce) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $nonce) !== 1
                || ! hash_equals($claim->session_binding_hash, $this->binding($request, $nonce))
                || ! $this->browserMatches($claim, $request)
                || ($claim->operation === 'link' && (! $customer || $customer->auth_version !== $claim->auth_version))) {
                return null;
            }
            $claim->forceFill(['consumed_at' => now()])->save();

            return $claim;
        });
    }

    private function link(CustomerGoogleAuthClaim $claim, GoogleOAuthIdentity $proof, Request $request): string
    {
        $result = DB::transaction(function () use ($claim, $proof, $request): Customer|string {
            $customer = Customer::query()->whereKey($claim->customer_id)->lockForUpdate()->first();
            if (! $customer || ! $this->browserMatches($claim, $request) || $customer->auth_version !== $claim->auth_version) {
                return 'invalid';
            }
            $owned = $this->googleIdentities()->where('customer_id', $customer->getKey())->lockForUpdate()->first();
            $subject = $this->googleIdentities()->where('provider_subject', $proof->subject)->first();
            if (($owned && $owned->provider_subject !== $proof->subject)
                || ($subject && $subject->customer_id !== $customer->getKey())) {
                return 'conflict';
            }
            try {
                DB::transaction(fn () => $this->saveIdentity($customer, $proof, $owned));
            } catch (QueryException $error) {
                if (! $this->ownershipViolation($error)) {
                    throw $error;
                }

                return 'conflict';
            }

            return $customer;
        });
        if (is_string($result)) {
            return $result;
        }
        $this->rotate($result, $request);

        return 'linked';
    }

    private function signIn(CustomerGoogleAuthClaim $claim, GoogleOAuthIdentity $proof, Request $request): string
    {
        $result = DB::transaction(function () use ($claim, $proof, $request): array|string {
            if (! $this->browserMatches($claim, $request)) {
                return 'invalid';
            }
            $snapshot = $this->googleIdentities()->where('provider_subject', $proof->subject)->first();
            if ($snapshot) {
                $customer = Customer::query()->whereKey($snapshot->customer_id)->lockForUpdate()->first();
                $identity = $this->googleIdentities()->whereKey($snapshot->getKey())->lockForUpdate()->first();
                if (! $customer || ! $identity || $identity->customer_id !== $customer->getKey() || $identity->provider_subject !== $proof->subject) {
                    return 'invalid';
                }
                $this->saveIdentity($customer, $proof, $identity);

                return [$customer, $identity->getKey()];
            }
            if (Customer::query()->where('email', $proof->email)->exists()) {
                return 'collision';
            }
            if ($claim->operation !== 'register') {
                return 'invalid';
            }
            try {
                // A uniqueness loser must roll back both the new Customer and its identity.
                $created = DB::transaction(function () use ($proof): array {
                    $customer = new Customer;
                    $customer->forceFill(['first_name' => 'Pelanggan', 'email' => $proof->email, 'email_verified_at' => now(), 'auth_version' => 0, 'enrollment_version' => 0])->save();
                    $identity = $this->saveIdentity($customer, $proof);

                    return [$customer, $identity->getKey()];
                });
            } catch (QueryException $error) {
                if (! $this->ownershipViolation($error)) {
                    throw $error;
                }

                return Customer::query()->where('email', $proof->email)->exists() ? 'collision' : 'conflict';
            }

            return $created;
        });
        if (is_string($result)) {
            return $result;
        }
        [$customer, $identityId] = $result;
        $this->sessions->authenticate($customer, $request);
        $request->session()->put('customer_google_identity_id', $identityId);

        return 'authenticated';
    }

    private function saveIdentity(Customer $customer, GoogleOAuthIdentity $proof, ?CustomerIdentity $identity = null): CustomerIdentity
    {
        if (! $identity) {
            $identity = new CustomerIdentity;
            $identity->forceFill([
                'customer_id' => $customer->getKey(),
                'provider' => CustomerIdentityProvider::Google,
                'provider_subject' => $proof->subject,
            ]);
        }
        $identity->forceFill(['provider_email' => $proof->email, 'last_used_at' => now()])->save();

        return $identity;
    }

    private function googleIdentities(): Builder
    {
        return CustomerIdentity::query()->where('provider', CustomerIdentityProvider::Google->value);
    }

    private function browserMatches(CustomerGoogleAuthClaim $claim, Request $request): bool
    {
        $authenticated = auth('customer')->user();
        if (in_array($claim->operation, ['login', 'register'], true)) {
            return $authenticated === null;
        }

        return $claim->operation === 'link' && $authenticated !== null
            && $authenticated->getAuthIdentifier() === $claim->customer_id
            && $request->session()->get('customer_auth_version') === $claim->auth_version
            && $claim->authenticated_at !== null
            && $request->session()->get('customer_authenticated_at') === $claim->authenticated_at->timestamp
            && $this->fresh($request);
    }

    private function fresh(Request $request): bool
    {
        $at = $request->session()->get('customer_authenticated_at');

        return is_int($at) && $at <= now()->timestamp && $at > now()->subMinutes(5)->timestamp;
    }

    private function configurationMatches(CustomerGoogleAuthClaim $claim): bool
    {
        $fingerprint = $this->provider->configurationFingerprint();

        return $fingerprint !== null && hash_equals($claim->configuration_fingerprint, $fingerprint);
    }

    private function hasAlternative(Customer $customer): bool
    {
        if (is_string($customer->email) && filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false
            && $customer->email_verified_at !== null && $customer->email_verified_at->lte(now())) {
            return true;
        }
        $actor = (string) $customer->telegram_id;

        return preg_match('/\A[1-9][0-9]{0,18}\z/', $actor) === 1
            && CustomerIdentity::query()->where('customer_id', $customer->getKey())
                ->where('provider', CustomerIdentityProvider::Telegram->value)->where('provider_subject', $actor)->exists();
    }

    private function rotate(Customer $customer, Request $request): void
    {
        auth('customer')->setUser($customer);
        $request->session()->regenerate(true);
    }

    private function ownershipViolation(QueryException $error): bool
    {
        return in_array($error->errorInfo[0] ?? null, ['23505', '23514', '23000'], true);
    }

    private function binding(Request $request, #[\SensitiveParameter] string $nonce): string
    {
        return hash('sha256', $request->session()->getId()."\0".$nonce);
    }

    private function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
