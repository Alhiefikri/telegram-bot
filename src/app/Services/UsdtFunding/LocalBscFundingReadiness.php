<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Services\Bsc\BscReadiness;
use InvalidArgumentException;

/** Readiness for the configured watch-only BSC rail. */
final class LocalBscFundingReadiness implements UsdtFundingRailReadiness
{
    public function railKey(): string
    {
        return CustomerUsdtFundingRail::BscUsdt->value;
    }

    public function readiness(): UsdtFundingRailReadinessObservation
    {
        if ((int) config('services.bsc.chain_id', 56) !== 56
            || strtolower((string) config('services.bsc.usdt_contract')) !== strtolower(BscReadiness::APPROVED_USDT_CONTRACT)
            || ! filled(config('services.bsc.rpc_urls'))) {
            return UsdtFundingRailReadinessObservation::unavailable('bsc_watch_only', 'bsc_configuration_unavailable');
        }

        return UsdtFundingRailReadinessObservation::ready('bsc_watch_only');
    }

    public function assertRail(CustomerUsdtFundingRail $rail): void
    {
        if ($rail !== CustomerUsdtFundingRail::BscUsdt) {
            throw new InvalidArgumentException('BSC readiness cannot authorize another rail.');
        }
    }
}
