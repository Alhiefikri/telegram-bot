<?php

namespace App\Services\Sakurupiah;

final class SakurupiahProviderLookupBudget
{
    private const LOOKUP_CHAIN_COUNT = 2;

    private const SAFETY_MARGIN_SECONDS = 8;

    public static function requiredLeaseSeconds(): int
    {
        return (int) ceil(self::lookupChainSeconds() + self::SAFETY_MARGIN_SECONDS);
    }

    public static function lookupChainSeconds(): float
    {
        return self::singleLookupSeconds() * self::LOOKUP_CHAIN_COUNT;
    }

    private static function singleLookupSeconds(): float
    {
        $attempts = max(1, (int) config('services.sakurupiah.lookup_max_attempts', 3));
        $timeoutSeconds = max(0.1, (float) config('services.sakurupiah.http_timeout_seconds', 10));
        $retryDelayMs = max(0, (int) config('services.sakurupiah.lookup_retry_delay_ms', 250));
        $retryMaxDelayMs = max(0, (int) config('services.sakurupiah.lookup_retry_max_delay_ms', 1000));
        $retryDelaySeconds = 0.0;

        for ($attempt = 1; $attempt < $attempts; $attempt++) {
            $retryDelaySeconds += min(
                $retryDelayMs * (2 ** ($attempt - 1)),
                $retryMaxDelayMs,
            ) / 1000;
        }

        return ($attempts * $timeoutSeconds) + $retryDelaySeconds;
    }
}
