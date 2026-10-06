<?php

namespace App\Services\UsdtFunding;

/**
 * Read-only snapshot of the versioned Customer USDT funding policy. It never
 * carries a business cap: H0-v1 replaces min/max/aggregate caps with the
 * representational guards enforced by the strict micro-USDT parser and the
 * bounded confirmation TTL.
 */
final readonly class CustomerUsdtFundingPolicyState
{
    public function __construct(
        public bool $fundingEnabled,
        public int $confirmationTtlSeconds,
        public int $applicationFeeMicro,
        public int $termsVersion,
        public int $version,
    ) {}

    /** @return array<string, int|bool> */
    public function auditValues(): array
    {
        return [
            'funding_enabled' => $this->fundingEnabled,
            'confirmation_ttl_seconds' => $this->confirmationTtlSeconds,
            'application_fee_micro' => $this->applicationFeeMicro,
            'terms_version' => $this->termsVersion,
            'version' => $this->version,
        ];
    }
}
