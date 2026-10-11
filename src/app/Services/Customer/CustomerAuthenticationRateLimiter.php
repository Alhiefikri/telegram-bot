<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class CustomerAuthenticationRateLimiter
{
    public function allowMail(string $email, string $ip): bool
    {
        return $this->reserve([
            ['mail-email', $email, 3, 900],
            ['mail-ip', $ip, 10, 3600],
            ['mail-cooldown', $email, 1, 60],
        ]) !== false;
    }

    public function allowOtpConsume(string $ip): bool
    {
        return $this->reserve([['otp-consume-ip', $ip, 30, 900]]) !== false;
    }

    public function allowTelegramStart(string $sessionId, string $ip): bool
    {
        return $this->reserve([['telegram-start', $sessionId."\0".$ip, 10, 900]]) !== false;
    }

    public function allowTelegramBrowser(string $sessionId, string $ip, bool $consume): bool
    {
        return $this->reserve([[$consume ? 'telegram-consume' : 'telegram-status', $sessionId."\0".$ip, $consume ? 30 : 120, 900]]) !== false;
    }

    public function allowTelegramProof(string $actorId): bool
    {
        return $this->reserve([['telegram-proof', $actorId, 30, 900]]) !== false;
    }

    public function reserveLogin(string $email, string $ip): array|false
    {
        return $this->reserve([
            ['login-pair', $email."\0".$ip, 5, 900],
            ['login-ip', $ip, 30, 900],
        ]);
    }

    public function releaseSuccessfulLogin(array $reservation): void
    {
        $this->withLock(function ($cache) use ($reservation): bool {
            foreach ($reservation as $key => $reserved) {
                $entry = $cache->get($key);
                if (is_array($entry) && $entry['expires_at'] === $reserved['expires_at'] && $entry['expires_at'] > now()->timestamp) {
                    $entry['count'] = max(0, $entry['count'] - 1);
                    if (! $cache->put($key, $entry, $entry['expires_at'] - now()->timestamp)) {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    private function reserve(array $limits): array|false
    {
        return $this->withLock(function ($cache) use ($limits): array|false {
            $entries = [];
            $timestamp = now()->timestamp;
            foreach ($limits as [$purpose, $identity, $maximum, $seconds]) {
                $key = $this->key($purpose, $identity);
                $entry = $cache->get($key);
                if (! is_array($entry) || $entry['expires_at'] <= $timestamp) {
                    $entry = ['count' => 0, 'expires_at' => $timestamp + $seconds];
                }
                if ($entry['count'] >= $maximum) {
                    return false;
                }
                $entry['count']++;
                $entries[$key] = $entry;
            }
            foreach ($entries as $key => $entry) {
                if (! $cache->put($key, $entry, $entry['expires_at'] - $timestamp)) {
                    return false;
                }
            }

            return $entries;
        });
    }

    private function withLock(callable $operation): array|bool
    {
        if (! is_string(config('app.key')) || config('app.key') === '') {
            return false;
        }
        try {
            $cache = Cache::store(config('cache.limiter'));
            $lock = $cache->lock('customer-auth:rate-limits', 5);
            if (! $lock->get()) {
                return false;
            }
            try {
                return $operation($cache);
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            return false;
        }
    }

    private function key(string $purpose, string $identity): string
    {
        return 'customer-auth:'.$purpose.':'.hash_hmac('sha256', $identity, (string) config('app.key'));
    }
}
