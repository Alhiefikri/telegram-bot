<?php

namespace App\Services\UsdtFunding;

use InvalidArgumentException;

/**
 * Closed typed statement of one rail readiness check: either a proven ready
 * state or an explicit reason it cannot serve new targets. A fixture in a
 * test is behaviour evidence only; it is never proof of an approved rail.
 * Provider keys and reasons are sanitized tokens safe to store and display.
 */
final readonly class UsdtFundingRailReadinessObservation
{
    private const TOKEN_PATTERN = '/\A[a-z0-9][a-z0-9_\-]{0,63}\z/D';

    private function __construct(
        public bool $ready,
        public string $providerKey,
        public ?string $reason,
    ) {}

    public static function ready(string $providerKey): self
    {
        return new self(true, self::token('provider key', $providerKey), null);
    }

    public static function unavailable(string $providerKey, string $reason): self
    {
        return new self(false, self::token('provider key', $providerKey), self::token('reason', $reason));
    }

    private static function token(string $label, string $value): string
    {
        if (preg_match(self::TOKEN_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("USDT funding rail {$label} must be a sanitized lowercase token.");
        }

        return $value;
    }
}
