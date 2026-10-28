<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class SakurupiahRequestBackpressure
{
    // Local recovery policy, not an advertised provider request-frequency limit.
    private const COOLDOWN_SECONDS = 60;

    private readonly SakurupiahEnvironment $environment;

    private int $blockedUntil = 0;

    public function __construct(?SakurupiahEnvironment $environment = null)
    {
        $this->environment = $environment ?? SakurupiahEnvironment::runtime();
    }

    public function retryAt(): ?int
    {
        try {
            $retryAt = max($this->blockedUntil, $this->cachedRetryAt());
        } catch (Throwable $exception) {
            throw new SakurupiahProviderException(
                'Sakurupiah merchant coordination cache is unavailable.',
                reason: 'cache_unavailable',
                retryable: true,
                previous: $exception,
            );
        }

        return $retryAt > now()->getTimestamp() ? $retryAt : null;
    }

    public function isCoolingDown(): bool
    {
        return $this->retryAt() !== null;
    }

    public function assertAvailable(string $operation, bool $lookup): void
    {
        try {
            $retryAt = $this->retryAt();
        } catch (SakurupiahProviderException $exception) {
            $this->defer($operation, $lookup, 'cache_unavailable', previous: $exception);
        }
        if ($retryAt !== null) {
            $this->defer($operation, $lookup, 'rate_limit_cooldown', $retryAt);
        }
    }

    public function acquireRequest(string $operation, bool $lookup, string $caller): Lock
    {
        $this->assertAvailable($operation, $lookup);
        try {
            $timeout = config('services.sakurupiah.http_timeout_seconds', 10);
            $lease = (int) ceil(is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 10) + 30;
            $lock = Cache::lock($this->cacheKey().':request', $lease);
            // Sweeps yield immediately; foreground callers wait at most one second.
            // No spacing or provider quota is inferred from this single-flight gate.
            $background = in_array($caller, ['reconciliation_command', 'top_up_reconciliation_command'], true);
            if ($background ? ! $lock->get() : ! $lock->block(1)) {
                throw new LockTimeoutException;
            }
        } catch (LockTimeoutException $exception) {
            $this->defer($operation, $lookup, 'lookup_temporarily_unavailable', now()->getTimestamp() + 1, $exception);
        } catch (Throwable $exception) {
            $this->defer($operation, $lookup, 'cache_unavailable', previous: $exception);
        }

        try {
            // Another process can have observed a throttle while we waited.
            $this->assertAvailable($operation, $lookup);
        } catch (Throwable $exception) {
            try {
                $lock->release();
            } catch (Throwable) {
                // Preserve the pre-dispatch classification.
            }
            throw $exception;
        }

        return $lock;
    }

    public function observe(Response $response): void
    {
        if (! self::isRateLimited($response)) {
            return;
        }

        $this->blockedUntil = max($this->blockedUntil, now()->getTimestamp() + self::COOLDOWN_SECONDS, $this->retryAfter($response));
        try {
            Cache::lock($this->cacheKey().':update', 5)->block(1, function (): void {
                $this->blockedUntil = max($this->blockedUntil, $this->cachedRetryAt());
                if (! Cache::put($this->cacheKey(), $this->blockedUntil, now()->setTimestamp($this->blockedUntil))) {
                    throw new RuntimeException('Sakurupiah merchant cooldown could not be stored.');
                }
            });
        } catch (Throwable) {
            // Preserve the actual provider response and this instance's cooldown.
        }
    }

    public static function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }
        if (! $response->successful() && $response->status() !== 400) {
            return false;
        }

        $payload = json_decode($response->body(), true, 32, JSON_BIGINT_AS_STRING);
        if (! is_array($payload) || array_is_list($payload)) {
            return false;
        }
        if (in_array($payload['status'] ?? null, [429, '429'], true)) {
            return true;
        }

        return in_array($payload['status'] ?? null, [400, '400'], true)
            && is_string($payload['message'] ?? null)
            && preg_match('/\Apermintaan\s+terlalu\s+cepat\s+silahkan\s+coba\s+lagi\s+nanti\.?\z/iu', trim($payload['message'])) === 1;
    }

    private function retryAfter(Response $response): int
    {
        $value = trim((string) $response->header('Retry-After'));
        if (preg_match('/\A[0-9]+\z/', $value) === 1) {
            $seconds = ltrim($value, '0') ?: '0';
            $maximum = (string) (PHP_INT_MAX - now()->getTimestamp());
            if (strlen($seconds) < strlen($maximum) || (strlen($seconds) === strlen($maximum) && strcmp($seconds, $maximum) <= 0)) {
                return now()->getTimestamp() + (int) $seconds;
            }

            return 0;
        }

        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone('GMT'));
            if ($date !== false && $date->format($format) === $value) {
                return $date->getTimestamp();
            }
        }

        return 0;
    }

    private function cachedRetryAt(): int
    {
        $value = Cache::get($this->cacheKey(), 0);
        if (is_string($value) && preg_match('/\A[0-9]{1,18}\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Sakurupiah merchant cooldown cache is invalid.');
        }

        return $value;
    }

    private function defer(string $operation, bool $lookup, string $reason, ?int $retryAt = null, ?Throwable $previous = null): never
    {
        if (! $lookup) {
            throw new SakurupiahCreatePreRequestException(
                'Sakurupiah create deferred before dispatch by merchant coordination.',
                previous: $previous,
                reason: $reason,
                retryAt: $retryAt,
            );
        }

        throw new SakurupiahProviderException(
            'Sakurupiah lookup deferred before dispatch by merchant coordination.',
            reason: $reason,
            retryable: true,
            operation: $operation,
            previous: $previous,
            retryAt: $retryAt,
        );
    }

    private function cacheKey(): string
    {
        return 'sakurupiah:backpressure:'.$this->environment->value.':'.hash('sha256',
            (string) config($this->environment->configKey('base_url')).'|'.(string) config($this->environment->configKey('api_id')),
        );
    }
}
