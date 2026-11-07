<?php

namespace App\Services\WijayaPay;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serializes provider dispatch per merchant and stores a bounded cooldown
 * after an observed HTTP 429. This keeps one checkout, the reconciliation
 * sweep and a top-up retry from producing a request burst per merchant.
 */
final class WijayaPayRequestGate
{
    private const COOLDOWN_SECONDS = 60;

    public function retryAt(): ?int
    {
        try {
            $value = Cache::get($this->cooldownKey(), 0);
            $retryAt = is_numeric($value) ? (int) $value : 0;
        } catch (Throwable $exception) {
            throw new WijayaPayProviderException(
                'WijayaPay request coordination cache is unavailable.',
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

    public function acquire(string $operation, bool $create = false): Lock
    {
        $retryAt = $this->retryAt();
        if ($retryAt !== null) {
            throw $this->deferral($operation, $create, 'rate_limit_cooldown', $retryAt);
        }

        try {
            $lock = Cache::lock($this->requestKey(), $this->lockLeaseSeconds());
            if (! $lock->block(2)) {
                throw new LockTimeoutException('WijayaPay request slot was busy.');
            }
        } catch (LockTimeoutException $exception) {
            throw $this->deferral($operation, $create, 'lookup_temporarily_unavailable', now()->getTimestamp() + 1, $exception);
        } catch (WijayaPayProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->deferral($operation, $create, 'cache_unavailable', null, $exception);
        }

        $retryAt = $this->retryAt();
        if ($retryAt !== null) {
            try {
                $lock->release();
            } catch (Throwable) {
                // The lease expires without changing an undispatched request.
            }

            throw $this->deferral($operation, $create, 'rate_limit_cooldown', $retryAt);
        }

        return $lock;
    }

    public function observe(Response $response): void
    {
        if (! self::isRateLimited($response)) {
            return;
        }

        $retryAt = max(
            now()->getTimestamp() + self::COOLDOWN_SECONDS,
            $this->retryAfter($response),
        );

        try {
            Cache::put($this->cooldownKey(), $retryAt, now()->setTimestamp($retryAt));
        } catch (Throwable) {
            // Preserve the provider response and let the caller decide.
        }
    }

    public static function isRateLimited(Response $response): bool
    {
        return $response->status() === 429;
    }

    private function retryAfter(Response $response): int
    {
        $header = trim((string) $response->header('Retry-After'));
        if ($header !== '' && ctype_digit($header)) {
            return now()->getTimestamp() + min(3600, max(1, (int) $header));
        }

        $remaining = trim((string) $response->header('X-RateLimit-Remaining'));
        if ($header === '' && $remaining === '0') {
            return now()->getTimestamp() + self::COOLDOWN_SECONDS;
        }

        return now()->getTimestamp() + self::COOLDOWN_SECONDS;
    }

    private function deferral(
        string $operation,
        bool $create,
        string $reason,
        ?int $retryAt,
        ?Throwable $previous = null,
    ): WijayaPayProviderException|WijayaPayCreatePreRequestException {
        if ($create) {
            return new WijayaPayCreatePreRequestException($reason, $previous);
        }

        return new WijayaPayProviderException(
            'WijayaPay '.$operation.' is temporarily deferred.',
            operation: $operation,
            retryable: true,
            reason: $reason,
            previous: $previous,
        );
    }

    private function cooldownKey(): string
    {
        return 'wijayapay:cooldown:'.$this->merchantFingerprint();
    }

    private function requestKey(): string
    {
        return 'wijayapay:request:'.$this->merchantFingerprint();
    }

    private function merchantFingerprint(): string
    {
        $merchant = trim((string) config('services.wijayapay.code_merchant'));

        return hash('sha256', $merchant);
    }

    private function lockLeaseSeconds(): int
    {
        $timeout = config('services.wijayapay.http_timeout_seconds', 10.0);
        $timeout = is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 10.0;

        return (int) ceil($timeout) + 30;
    }
}
