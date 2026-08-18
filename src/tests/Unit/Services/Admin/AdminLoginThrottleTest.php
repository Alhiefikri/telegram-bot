<?php

namespace Tests\Unit\Services\Admin;

use App\Services\Admin\AdminLoginThrottle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use PHPUnit\Framework\TestCase;

class AdminLoginThrottleTest extends TestCase
{
    public function test_default_policy_preserves_the_recommended_identity_and_ip_windows(): void
    {
        $policy = $this->throttle()->policy();

        $this->assertSame(5, $policy->identityMaxAttempts);
        $this->assertSame(60, $policy->identityDecaySeconds);
        $this->assertSame(10, $policy->ipMaxAttempts);
        $this->assertSame(60, $policy->ipDecaySeconds);
    }

    public function test_policy_values_are_configurable_independently(): void
    {
        $policy = $this->throttle([
            'identity_max_attempts' => 7,
            'identity_decay_seconds' => 11,
            'ip_max_attempts' => 13,
            'ip_decay_seconds' => 17,
        ])->policy();

        $this->assertSame(7, $policy->identityMaxAttempts);
        $this->assertSame(11, $policy->identityDecaySeconds);
        $this->assertSame(13, $policy->ipMaxAttempts);
        $this->assertSame(17, $policy->ipDecaySeconds);
    }

    public function test_record_failure_decides_from_the_atomic_incremented_counts(): void
    {
        $throttle = $this->throttle();

        foreach (range(1, 5) as $attempt) {
            $decision = $throttle->recordFailure('Admin@Example.Test', '203.0.113.10');

            $this->assertFalse($decision->blocked);
            $this->assertSame($attempt, $decision->identityAttempts);
            $this->assertSame($attempt, $decision->ipAttempts);
        }

        $decision = $throttle->recordFailure('admin@example.test', '203.0.113.10');

        $this->assertTrue($decision->blocked);
        $this->assertSame(6, $decision->identityAttempts);
        $this->assertSame(6, $decision->ipAttempts);
        $this->assertGreaterThan(0, $decision->retryAfter);
    }

    public function test_identity_clear_does_not_clear_the_global_ip_bucket(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure('admin@example.test', '203.0.113.11');

        $throttle->clearIdentity('ADMIN@example.test', '203.0.113.11');
        $decision = $throttle->check('admin@example.test', '203.0.113.11');

        $this->assertFalse($decision->blocked);
        $this->assertSame(0, $decision->identityAttempts);
        $this->assertSame(1, $decision->ipAttempts);
    }

    public function test_keys_normalize_login_and_ip_values(): void
    {
        $throttle = $this->throttle();

        $this->assertSame(
            'admin@example.test|203.0.113.12',
            $throttle->identityKey(' Admin@Example.Test ', ' 203.0.113.12 '),
        );
        $this->assertSame(
            'admin-login:ip:203.0.113.12',
            $throttle->ipKey(' 203.0.113.12 '),
        );
    }

    /** @param array<string, int> $values */
    private function throttle(array $values = []): AdminLoginThrottle
    {
        return new AdminLoginThrottle(
            new RateLimiter(new CacheRepository(new ArrayStore)),
            new ConfigRepository(['auth' => ['admin_login_throttle' => $values]]),
        );
    }
}
