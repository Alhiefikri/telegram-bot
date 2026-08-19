<?php

namespace App\Services\Admin;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

final class AdminLoginThrottle
{
    private const IP_KEY_PREFIX = 'admin-login:ip:';
    private const LOCK_KEY_PREFIX = 'admin-login-throttle:lock:';
    private const LOCK_SECONDS = 30;
    private const LOCK_WAIT_SECONDS = 30;

    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly CacheRepository $cache,
        private readonly Repository $config,
    ) {}

    public function policy(): AdminLoginThrottlePolicy
    {
        $values = (array) $this->config->get('auth.admin_login_throttle', []);

        return new AdminLoginThrottlePolicy(
            identityMaxAttempts: max(1, (int) ($values['identity_max_attempts'] ?? 5)),
            identityDecaySeconds: max(1, (int) ($values['identity_decay_seconds'] ?? 60)),
            ipMaxAttempts: max(1, (int) ($values['ip_max_attempts'] ?? 10)),
            ipDecaySeconds: max(1, (int) ($values['ip_decay_seconds'] ?? 60)),
        );
    }

    /**
     * Check whether a login is already blocked before doing password work.
     *
     * The identity and IP buckets are read while holding the same per-IP lock
     * used by recordFailure(), so the decision describes one cache state.
     */
    public function check(string $login, string $ip): AdminLoginThrottleDecision
    {
        $policy = $this->policy();
        $identityKey = $this->identityKey($login, $ip);
        $ipKey = $this->ipKey($ip);

        return $this->withIpLock($ip, function () use ($policy, $identityKey, $ipKey): AdminLoginThrottleDecision {
            $identityBlocked = $this->rateLimiter->tooManyAttempts($identityKey, $policy->identityMaxAttempts);
            $ipBlocked = $this->rateLimiter->tooManyAttempts($ipKey, $policy->ipMaxAttempts);
            $blocked = $identityBlocked || $ipBlocked;

            return new AdminLoginThrottleDecision(
                blocked: $blocked,
                identityAttempts: (int) $this->rateLimiter->attempts($identityKey),
                ipAttempts: (int) $this->rateLimiter->attempts($ipKey),
                retryAfter: $blocked ? max(
                    1,
                    $identityBlocked ? (int) $this->rateLimiter->availableIn($identityKey) : 0,
                    $ipBlocked ? (int) $this->rateLimiter->availableIn($ipKey) : 0,
                ) : 0,
            );
        });
    }

    /**
     * Record a failed admin login in both buckets.
     *
     * Both increments and the blocked decision are one critical section. The
     * comparison is intentionally against a value greater than the configured
     * maximum: the fifth (or tenth) failure is still processed, and the next
     * request is blocked by the preflight check or this racing failure.
     */
    public function recordFailure(string $login, string $ip): AdminLoginThrottleDecision
    {
        $policy = $this->policy();
        $identityKey = $this->identityKey($login, $ip);
        $ipKey = $this->ipKey($ip);

        return $this->withIpLock($ip, function () use ($policy, $identityKey, $ipKey): AdminLoginThrottleDecision {
            $identityAttempts = (int) $this->rateLimiter->increment($identityKey, $policy->identityDecaySeconds);
            $ipAttempts = (int) $this->rateLimiter->increment($ipKey, $policy->ipDecaySeconds);
            $blockedByIdentity = $identityAttempts > $policy->identityMaxAttempts;
            $blockedByIp = $ipAttempts > $policy->ipMaxAttempts;

            return new AdminLoginThrottleDecision(
                blocked: $blockedByIdentity || $blockedByIp,
                identityAttempts: $identityAttempts,
                ipAttempts: $ipAttempts,
                retryAfter: $blockedByIdentity || $blockedByIp ? max(
                    1,
                    $blockedByIdentity ? (int) $this->rateLimiter->availableIn($identityKey) : 0,
                    $blockedByIp ? (int) $this->rateLimiter->availableIn($ipKey) : 0,
                ) : 0,
            );
        });
    }

    public function clearIdentity(string $login, string $ip): void
    {
        $identityKey = $this->identityKey($login, $ip);

        $this->withIpLock($ip, function () use ($identityKey): void {
            $this->rateLimiter->clear($identityKey);
        });
    }

    public function identityKey(string $login, string $ip): string
    {
        return Str::lower(trim($login)).'|'.$this->normalizeIp($ip);
    }

    public function ipKey(string $ip): string
    {
        return self::IP_KEY_PREFIX.$this->normalizeIp($ip);
    }

    private function withIpLock(string $ip, callable $callback): mixed
    {
        return $this->cache->withoutOverlapping(
            self::LOCK_KEY_PREFIX.$this->normalizeIp($ip),
            $callback,
            self::LOCK_SECONDS,
            self::LOCK_WAIT_SECONDS,
        );
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return 'unknown';
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        $normalized = inet_ntop($packed);

        return $normalized === false ? $ip : $normalized;
    }
}
