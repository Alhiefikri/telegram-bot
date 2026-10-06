<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use LogicException;

/**
 * Resolves the readiness provider for one USDT funding rail. The provider set
 * is intentionally empty in this release: USDT funding stays disabled until
 * H1/A1 qualify a rail and a provider is registered under the
 * `usdt-funding-rail-readiness` container tag.
 */
final class UsdtFundingRailReadinessRegistry
{
    /** @var array<string, UsdtFundingRailReadiness> */
    private array $providers = [];

    /** @param iterable<UsdtFundingRailReadiness> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $key = $provider->railKey();
            if (array_key_exists($key, $this->providers)) {
                throw new LogicException("Duplicate USDT funding rail readiness provider [{$key}].");
            }

            $this->providers[$key] = $provider;
        }
    }

    public function isRegistered(CustomerUsdtFundingRail $rail): bool
    {
        return array_key_exists($rail->value, $this->providers);
    }

    public function observe(CustomerUsdtFundingRail $rail): UsdtFundingRailReadinessObservation
    {
        $provider = $this->providers[$rail->value] ?? null;
        if ($provider === null) {
            return UsdtFundingRailReadinessObservation::unavailable($rail->value, 'not_registered');
        }

        $provider->assertRail($rail);

        return $provider->readiness();
    }
}
