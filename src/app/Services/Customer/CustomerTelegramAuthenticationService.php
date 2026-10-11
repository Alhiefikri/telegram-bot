<?php

namespace App\Services\Customer;

use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\CustomerTelegramAuthClaim;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CustomerTelegramAuthenticationService
{
    public function __construct(
        private CustomerAuthenticationRateLimiter $limiter,
        private CustomerSessionAuthentication $sessions,
    ) {}

    public function ready(): bool
    {
        return is_string(config('services.telegram.token'))
            && trim(config('services.telegram.token')) !== ''
            && $this->botUsername() !== null;
    }

    public function start(Request $request, bool $link): ?array
    {
        $request->session()->forget(['customer_telegram_claim_id', 'customer_telegram_claim_nonce', 'customer_telegram_claim_error']);
        try {
            if (! $this->ready() || ! $this->limiter->allowTelegramStart($request->session()->getId(), (string) $request->ip())) {
                $request->session()->put('customer_telegram_claim_error', 'unavailable');

                return null;
            }
            $authenticated = auth('customer')->user();
            if (($link && (! $authenticated || ! $this->fresh($request))) || (! $link && $authenticated)) {
                $request->session()->put('customer_telegram_claim_error', 'invalid');

                return null;
            }
            $nonce = $this->secret();
            $capability = 'wa_'.$this->secret();
            $claim = DB::transaction(function () use ($request, $link, $authenticated, $nonce, $capability): ?CustomerTelegramAuthClaim {
                $customer = $link ? Customer::query()->whereKey($authenticated->getAuthIdentifier())->lockForUpdate()->first() : null;
                if ($link && (! $customer || $request->session()->get('customer_auth_version') !== $customer->auth_version)) {
                    return null;
                }
                $createdAt = now();
                $claim = new CustomerTelegramAuthClaim;
                $claim->forceFill([
                    'operation' => $link ? 'link' : 'login',
                    'customer_id' => $customer?->getKey(),
                    'auth_version' => $customer?->auth_version,
                    'authenticated_at' => $link ? now()->setTimestamp($request->session()->get('customer_authenticated_at')) : null,
                    'session_binding_hash' => $this->binding($request, $nonce),
                    'capability_hash' => hash('sha256', $capability),
                    'expires_at' => $createdAt->copy()->addMinutes(5),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();

                return $claim;
            });
            if (! $claim) {
                $request->session()->put('customer_telegram_claim_error', 'invalid');

                return null;
            }
            $request->session()->put('customer_telegram_claim_id', $claim->getKey());
            $request->session()->put('customer_telegram_claim_nonce', $nonce);

            return ['deep_link' => 'https://t.me/'.$this->botUsername().'?start='.$capability, 'expires_in' => 300];
        } catch (Throwable) {
            $request->session()->put('customer_telegram_claim_error', 'unavailable');

            return null;
        }
    }

    public function status(Request $request): string
    {
        try {
            if (! $this->ready() || ! $this->limiter->allowTelegramBrowser($request->session()->getId(), (string) $request->ip(), false)) {
                return 'unavailable';
            }
            $claim = $this->browserClaim($request);
            if (! $claim || ! $this->browserMatches($claim, $request, false)) {
                return $request->session()->get('customer_telegram_claim_error') === 'unavailable' ? 'unavailable' : 'invalid';
            }
            if ($claim->expires_at->lte(now())) {
                return 'expired';
            }
            if ($claim->failure !== null) {
                return $claim->failure;
            }
            if ($claim->consumed_at !== null || ! $this->versionMatches($claim)
                || ($claim->operation === 'link' && (! $this->fresh($request) || ! $this->claimFresh($claim)))) {
                return 'invalid';
            }

            return $claim->confirmed_at !== null ? 'confirmed' : 'pending';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    public function prepare(#[\SensitiveParameter] string $capability, string $actorId, string $chatId, string $chatType): ?string
    {
        if (! $this->privateActor($actorId, $chatId, $chatType) || preg_match('/\Awa_[A-Za-z0-9_-]{43}\z/', $capability) !== 1) {
            return null;
        }
        try {
            if (! $this->ready() || ! $this->limiter->allowTelegramProof($actorId)) {
                return null;
            }
            $snapshot = CustomerTelegramAuthClaim::query()->where('capability_hash', hash('sha256', $capability))->first();
            if (! $snapshot) {
                return null;
            }

            return DB::transaction(function () use ($snapshot, $actorId): ?string {
                $customerId = $snapshot->operation === 'link' ? $snapshot->customer_id : Customer::query()->where('telegram_id', $actorId)->value('id');
                $customer = $customerId ? Customer::query()->whereKey($customerId)->lockForUpdate()->first() : null;
                $claim = CustomerTelegramAuthClaim::query()->whereKey($snapshot->getKey())->lockForUpdate()->first();
                if (! $this->active($claim) || $claim->confirmed_at !== null || ($claim->actor_id !== null && $claim->actor_id !== $actorId)) {
                    return null;
                }
                if (! $customer || ($claim->operation === 'login' && (string) $customer->telegram_id !== $actorId)) {
                    $this->fail($claim, 'conflict');

                    return null;
                }
                if ($claim->operation === 'link' && ($customer->auth_version !== $claim->auth_version || ! $this->claimFresh($claim))) {
                    $this->fail($claim, 'invalid');

                    return null;
                }
                if ($claim->prepared_at !== null && ($claim->proof_customer_id !== $customer->getKey() || $claim->proof_auth_version !== $customer->auth_version)) {
                    $this->fail($claim, 'invalid');

                    return null;
                }
                if ($this->collision($customer, $actorId, $claim->operation === 'link')) {
                    $this->fail($claim, 'conflict');

                    return null;
                }
                $confirmation = 'wa_confirm:'.$this->secret();
                $claim->forceFill([
                    'actor_id' => $actorId,
                    'proof_customer_id' => $customer->getKey(),
                    'proof_auth_version' => $customer->auth_version,
                    'confirmation_hash' => hash('sha256', $confirmation),
                    'prepared_at' => $claim->prepared_at ?? now(),
                ])->save();

                return $confirmation;
            });
        } catch (Throwable) {
            return null;
        }
    }

    public function confirm(#[\SensitiveParameter] string $confirmation, string $actorId, string $chatId, string $chatType): bool
    {
        if (! $this->privateActor($actorId, $chatId, $chatType) || preg_match('/\Awa_confirm:[A-Za-z0-9_-]{43}\z/', $confirmation) !== 1) {
            return false;
        }
        try {
            if (! $this->ready() || ! $this->limiter->allowTelegramProof($actorId)) {
                return false;
            }
            $hash = hash('sha256', $confirmation);
            $snapshot = CustomerTelegramAuthClaim::query()->where('confirmation_hash', $hash)->first();
            if (! $snapshot) {
                return false;
            }

            return DB::transaction(function () use ($snapshot, $hash, $actorId): bool {
                $customer = Customer::query()->whereKey($snapshot->proof_customer_id)->lockForUpdate()->first();
                $claim = CustomerTelegramAuthClaim::query()->whereKey($snapshot->getKey())->lockForUpdate()->first();
                if (! $this->active($claim) || $claim->confirmation_hash !== $hash || $claim->confirmed_at !== null || $claim->actor_id !== $actorId) {
                    return false;
                }
                if (! $customer || $customer->auth_version !== $claim->proof_auth_version || ($claim->operation === 'link' && ! $this->claimFresh($claim))) {
                    $this->fail($claim, 'invalid');

                    return false;
                }
                if ($this->collision($customer, $actorId, $claim->operation === 'link')) {
                    $this->fail($claim, 'conflict');

                    return false;
                }
                $claim->forceFill(['confirmed_at' => now()])->save();

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function consume(Request $request): bool
    {
        try {
            if (! $this->ready() || ! $this->limiter->allowTelegramBrowser($request->session()->getId(), (string) $request->ip(), true)) {
                return false;
            }
            $snapshot = $this->browserClaim($request);
            if (! $snapshot || $snapshot->proof_customer_id === null) {
                return false;
            }
            $customer = DB::transaction(function () use ($snapshot, $request): ?Customer {
                // Reset and proof consumption use the same Customer-first lock order.
                $customer = Customer::query()->whereKey($snapshot->proof_customer_id)->lockForUpdate()->first();
                $claim = CustomerTelegramAuthClaim::query()->whereKey($snapshot->getKey())->lockForUpdate()->first();
                if (! $this->active($claim) || $claim->confirmed_at === null || ! $this->browserMatches($claim, $request)) {
                    return null;
                }
                if (! $customer || $customer->auth_version !== $claim->proof_auth_version || ($claim->operation === 'link' && $customer->auth_version !== $claim->auth_version)) {
                    $this->fail($claim, 'invalid');

                    return null;
                }
                if ($this->collision($customer, $claim->actor_id, $claim->operation === 'link')) {
                    $this->fail($claim, 'conflict');

                    return null;
                }
                try {
                    // PostgreSQL uniqueness errors must roll back a savepoint, not poison the claim transaction.
                    DB::transaction(function () use ($customer, $claim): void {
                        if ($claim->operation === 'link') {
                            $customer->forceFill(['telegram_id' => $claim->actor_id])->save();
                        }
                        $identity = CustomerIdentity::query()->where('provider', CustomerIdentityProvider::Telegram->value)->where('provider_subject', $claim->actor_id)->first();
                        if (! $identity) {
                            $identity = new CustomerIdentity;
                            $identity->forceFill([
                                'customer_id' => $customer->getKey(),
                                'provider' => CustomerIdentityProvider::Telegram,
                                'provider_subject' => $claim->actor_id,
                            ]);
                        }
                        $identity->forceFill(['last_used_at' => now()])->save();
                    });
                } catch (QueryException $error) {
                    if (! in_array($error->errorInfo[0] ?? null, ['23505', '23514', '23000'], true)) {
                        throw $error;
                    }
                    $this->fail($claim, 'conflict');

                    return null;
                }
                $claim->forceFill(['consumed_at' => now()])->save();

                return $customer;
            });
            if (! $customer) {
                return false;
            }
            $this->sessions->authenticate($customer, $request);
            $request->session()->put('customer_telegram_authenticated', true);
            $request->session()->forget(['customer_telegram_claim_id', 'customer_telegram_claim_nonce', 'customer_telegram_claim_error']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function collision(Customer $customer, string $actorId, bool $link): bool
    {
        if ($link && $customer->telegram_id !== null) {
            return true;
        }
        if (! $link && (string) $customer->telegram_id !== $actorId) {
            return true;
        }
        if (Customer::query()->where('telegram_id', $actorId)->where('id', '<>', $customer->getKey())->exists()) {
            return true;
        }
        $identities = CustomerIdentity::query()->where('provider', CustomerIdentityProvider::Telegram->value)
            ->where(function ($query) use ($customer, $actorId): void {
                $query->where('customer_id', $customer->getKey())->orWhere('provider_subject', $actorId);
            })->get();
        foreach ($identities as $identity) {
            if ($identity->customer_id !== $customer->getKey() || $identity->provider_subject !== $actorId || $link) {
                return true;
            }
        }

        return false;
    }

    private function browserClaim(Request $request): ?CustomerTelegramAuthClaim
    {
        $id = $request->session()->get('customer_telegram_claim_id');

        return is_int($id) ? CustomerTelegramAuthClaim::query()->find($id) : null;
    }

    private function browserMatches(CustomerTelegramAuthClaim $claim, Request $request, bool $requireFresh = true): bool
    {
        $nonce = $request->session()->get('customer_telegram_claim_nonce');
        if (! is_string($nonce) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $nonce) !== 1 || ! hash_equals($claim->session_binding_hash, $this->binding($request, $nonce))) {
            return false;
        }
        $customer = auth('customer')->user();
        if ($claim->operation === 'login') {
            return $customer === null;
        }

        return $customer !== null && $customer->getAuthIdentifier() === $claim->customer_id
            && $request->session()->get('customer_auth_version') === $claim->auth_version
            && (! $requireFresh || ($this->fresh($request) && $this->claimFresh($claim)));
    }

    private function versionMatches(CustomerTelegramAuthClaim $claim): bool
    {
        $id = $claim->proof_customer_id ?? $claim->customer_id;
        $version = $claim->proof_auth_version ?? $claim->auth_version;

        return $id === null || Customer::query()->whereKey($id)->where('auth_version', $version)->exists();
    }

    private function active(?CustomerTelegramAuthClaim $claim): bool
    {
        return $claim !== null && $claim->failure === null && $claim->consumed_at === null && $claim->expires_at->gt(now());
    }

    private function fresh(Request $request): bool
    {
        $at = $request->session()->get('customer_authenticated_at');

        return is_int($at) && $at <= now()->timestamp && $at > now()->subMinutes(5)->timestamp;
    }

    private function claimFresh(CustomerTelegramAuthClaim $claim): bool
    {
        return $claim->authenticated_at !== null && $claim->authenticated_at->lte(now()) && $claim->authenticated_at->gt(now()->subMinutes(5));
    }

    private function fail(CustomerTelegramAuthClaim $claim, string $failure): void
    {
        $claim->forceFill(['failure' => $failure])->save();
    }

    private function privateActor(string $actorId, string $chatId, string $chatType): bool
    {
        return $chatType === 'private' && $actorId === $chatId && preg_match('/\A[1-9][0-9]{0,18}\z/', $actorId) === 1
            && (strlen($actorId) < 19 || strcmp($actorId, '9223372036854775807') <= 0);
    }

    private function binding(Request $request, #[\SensitiveParameter] string $nonce): string
    {
        return hash('sha256', $request->session()->getId()."\0".$nonce);
    }

    private function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function botUsername(): ?string
    {
        $url = config('storefront.links.telegram_bot_url');
        if (! is_string($url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 't.me'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/\A\/([A-Za-z][A-Za-z0-9_]{4,31})\z/', $parts['path'] ?? '', $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
