<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use InvalidArgumentException;

/** Technical-only U5 path; never a production funding authorization. */
final class LocalBscFundingReadiness implements UsdtFundingRailReadiness
{
    public function railKey(): string
    {
        return CustomerUsdtFundingRail::BscUsdt->value;
    }

    public function readiness(): UsdtFundingRailReadinessObservation
    {
        return app()->environment(['local', 'testing'])
            ? UsdtFundingRailReadinessObservation::ready('bsc_watch_only_disposable')
            : UsdtFundingRailReadinessObservation::unavailable('bsc_watch_only_disposable', 'h1_funding_unqualified');
    }

    public function assertRail(CustomerUsdtFundingRail $rail): void
    {
        if ($rail !== CustomerUsdtFundingRail::BscUsdt) {
            throw new InvalidArgumentException('BSC readiness cannot authorize another rail.');
        }
    }
}
