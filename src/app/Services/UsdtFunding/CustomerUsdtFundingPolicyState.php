<?php

namespace App\Services\UsdtFunding;

use App\Services\UsdtBalance\UsdtMicroAmount;

/**
 * Read-only snapshot of the versioned Customer USDT funding policy. It never
 * carries a business cap: H0-v1 replaces min/max/aggregate caps with the
 * representational guards enforced by the strict micro-USDT parser and the
 * bounded confirmation TTL. The USD preset list is presentation-only: it
 * seeds the top-up amount buttons and never limits what a Customer may fund.
 */
final readonly class CustomerUsdtFundingPolicyState
{
    /** @param list<int> $presetAmountsMicro */
    public function __construct(
        public bool $fundingEnabled,
        public int $confirmationTtlSeconds,
        public int $applicationFeeMicro,
        public array $presetAmountsMicro,
        public int $termsVersion,
        public int $version,
    ) {}

    /**
     * The presets as the Admin textarea shows them: canonical USDT units with
     * trailing zeros trimmed (e.g. "1, 5, 10, 25, 50, 100").
     */
    public function presetAmountsInput(): string
    {
        return implode(', ', array_map(
            static fn (int $amount): string => UsdtMicroAmount::formatUnits($amount),
            $this->presetAmountsMicro,
        ));
    }

    /** @return array<string, int|bool|list<int>> */
    public function auditValues(): array
    {
        return [
            'funding_enabled' => $this->fundingEnabled,
            'confirmation_ttl_seconds' => $this->confirmationTtlSeconds,
            'application_fee_micro' => $this->applicationFeeMicro,
            'preset_amounts_micro' => $this->presetAmountsMicro,
            'terms_version' => $this->termsVersion,
            'version' => $this->version,
        ];
    }
}
