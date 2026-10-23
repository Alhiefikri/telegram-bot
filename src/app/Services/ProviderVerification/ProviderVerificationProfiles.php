<?php

namespace App\Services\ProviderVerification;

use Closure;
use Illuminate\Http\Client\PendingRequest;

/**
 * Keeps request-specific provider budgets out of provider business semantics.
 * The profile is process-local, so queued and HTTP callers cannot leak a
 * budget into the next request handled by a long-lived worker.
 */
final class ProviderVerificationProfiles
{
    private static ?ProviderVerificationProfile $active = null;

    private static ?int $deadline = null;

    private static ?int $requestLimit = null;

    private static int $requests = 0;

    private static ?string $stopped = null;

    public static function until(int $deadline, Closure $callback, ?int $requestLimit = null): mixed
    {
        $previous = [self::$deadline, self::$requestLimit, self::$requests, self::$stopped];
        self::$deadline = self::$deadline === null ? $deadline : min(self::$deadline, $deadline);
        self::$requestLimit = $requestLimit;
        self::$requests = 0;
        self::$stopped = null;
        try {
            return $callback();
        } finally {
            [self::$deadline, self::$requestLimit, self::$requests, self::$stopped] = $previous;
        }
    }

    public static function deadline(): ?int
    {
        return self::$deadline;
    }

    public static function requests(): int
    {
        return self::$requests;
    }

    public static function stopReason(): ?string
    {
        if (self::$stopped !== null) {
            return self::$stopped;
        }
        if (self::$deadline !== null && hrtime(true) >= self::$deadline) {
            return 'deadline';
        }
        if (self::$requestLimit !== null && self::$requests >= self::$requestLimit) {
            return 'request_limit';
        }

        return null;
    }

    public static function prepareRequest(PendingRequest $request, float $timeout, float $connectTimeout): PendingRequest
    {
        $request->timeout($timeout)->connectTimeout($connectTimeout);
        if (self::$deadline === null) {
            return $request;
        }

        // Recompute at dispatch, not while building a request that may wait
        // behind middleware. Every retry passes this boundary independently.
        return $request->withMiddleware(static function (callable $handler): Closure {
            return static function ($message, array $options) use ($handler) {
                if (($reason = self::stopReason()) !== null) {
                    throw new ProviderVerificationBudgetExhausted($reason);
                }
                $remaining = (self::$deadline - hrtime(true)) / 1_000_000_000;
                // cURL rounds sub-millisecond timeouts to zero (unlimited).
                if ($remaining < 0.001) {
                    self::$stopped = 'deadline';
                    throw new ProviderVerificationBudgetExhausted('deadline');
                }
                $options['timeout'] = min($options['timeout'], floor($remaining * 1000) / 1000);
                $options['connect_timeout'] = min($options['connect_timeout'], $options['timeout']);
                self::$requests++;

                return $handler($message, $options);
            };
        });
    }

    public static function pause(int $milliseconds): void
    {
        if (($reason = self::stopReason()) !== null) {
            throw new ProviderVerificationBudgetExhausted($reason);
        }
        if (self::$deadline !== null && hrtime(true) + ($milliseconds * 1_000_000) >= self::$deadline) {
            self::$stopped = 'deadline';
            throw new ProviderVerificationBudgetExhausted('deadline');
        }
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(ProviderVerificationProfile $profile, Closure $callback): mixed
    {
        $previous = self::$active;
        self::$active = $profile;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }

    public static function active(): ProviderVerificationProfile
    {
        return self::$active ?? ProviderVerificationProfile::Reconciliation;
    }

    /** @return array{timeout_seconds: float, connect_timeout_seconds: float, max_attempts: int, retry_delay_ms: int, retry_max_delay_ms: int, lock_safety_margin_seconds: int} */
    public static function budget(?ProviderVerificationProfile $profile = null): array
    {
        $values = config('services.provider_verification.'.($profile ?? self::active())->value, []);

        return [
            'timeout_seconds' => max(0.1, (float) ($values['timeout_seconds'] ?? 10)),
            'connect_timeout_seconds' => max(0.1, (float) ($values['connect_timeout_seconds'] ?? 3)),
            'max_attempts' => max(1, (int) ($values['max_attempts'] ?? 3)),
            'retry_delay_ms' => max(0, (int) ($values['retry_delay_ms'] ?? 250)),
            'retry_max_delay_ms' => max(0, (int) ($values['retry_max_delay_ms'] ?? 1000)),
            'lock_safety_margin_seconds' => max(1, (int) ($values['lock_safety_margin_seconds'] ?? 8)),
        ];
    }

    public static function requiredLeaseSeconds(): int
    {
        $budget = self::budget(ProviderVerificationProfile::Reconciliation);
        $delays = 0;
        for ($attempt = 1; $attempt < $budget['max_attempts']; $attempt++) {
            $delays += min($budget['retry_delay_ms'] * (2 ** ($attempt - 1)), $budget['retry_max_delay_ms']);
        }

        return (int) ceil(($budget['timeout_seconds'] * $budget['max_attempts']) + ($delays / 1000))
            + $budget['lock_safety_margin_seconds'];
    }
}
