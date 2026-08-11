<?php

namespace App\Services\ProviderVerification;

use Closure;

/**
 * Keeps request-specific provider budgets out of provider business semantics.
 * The profile is process-local, so queued and HTTP callers cannot leak a
 * budget into the next request handled by a long-lived worker.
 */
final class ProviderVerificationProfiles
{
    private static ?ProviderVerificationProfile $active = null;

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
